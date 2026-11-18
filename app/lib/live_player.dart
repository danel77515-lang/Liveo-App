import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_chrome_cast/flutter_chrome_cast.dart'
    show GoogleCastDevice;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:video_player/video_player.dart';
import 'package:webview_flutter/webview_flutter.dart';

import 'cast_service.dart';
import 'models.dart';
import 'native_bridge.dart';
import 'theme.dart';

/// Reproductor propio de Liveo para enlaces m3u8 (HLS en vivo).
///
/// Minimalista: video a pantalla completa en horizontal, controles que se
/// ocultan solos, selector de fuentes, imagen en imagen (PiP) y Chromecast.
class LivePlayerPage extends StatefulWidget {
  const LivePlayerPage({super.key, required this.event, this.initialIndex = 0});

  final LiveEvent event;

  /// Fuente con la que arranca (la elegida en la ventana de opciones).
  final int initialIndex;

  @override
  State<LivePlayerPage> createState() => _LivePlayerPageState();
}

class _LivePlayerPageState extends State<LivePlayerPage> {
  VideoPlayerController? _vc;
  WebViewController? _web; // fuente iframe (página web)
  bool _webLoading = false;
  int _index = 0;
  bool _landscape = false; // arranca en vertical; horizontal es opcional
  int _gen = 0;
  bool _controls = true;
  bool _starting = true;
  bool _disposed = false;
  String? _error;
  int _retries = 0;
  Timer? _hideTimer;
  Timer? _retryTimer;

  bool _casting = false;
  String _castName = '';
  bool _remotePlaying = true;
  StreamSubscription<bool>? _castSub;

  List<StreamSource> get _links => widget.event.links;
  StreamSource get _src => _links[_index];
  bool get _isIframe => _links.isNotEmpty && _links[_index].isIframe;

  @override
  void initState() {
    super.initState();
    NativeBridge.ensureInit();
    NativeBridge.inPip.addListener(_onPip);
    ScreenMode.portrait();
    NativeBridge.keepScreenOn(true);
    SharedPreferences.getInstance().then((p) {
      if (mounted) {
        NativeBridge.setPipReady(p.getBool('pipOnLeave') ?? true);
      }
    });
    if (CastService.ready) {
      _castSub = CastService.connectedStream.listen((connected) {
        if (_casting && !connected && mounted) {
          setState(() => _casting = false);
          _vc?.play();
        }
      });
    }
    final start = widget.initialIndex;
    _open(start >= 0 && start < _links.length ? start : 0);
  }

  Future<void> _toggleOrientation() async {
    final on = !_landscape;
    setState(() => _landscape = on);
    await ScreenMode.setLandscape(on);
    _showControls();
  }

  @override
  void dispose() {
    _disposed = true;
    _hideTimer?.cancel();
    _retryTimer?.cancel();
    _castSub?.cancel();
    NativeBridge.inPip.removeListener(_onPip);
    final c = _vc;
    _vc = null;
    c?.removeListener(_onTick);
    c?.dispose();
    if (_casting) CastService.stop();
    CastService.stopDiscovery();
    NativeBridge.setPipReady(false);
    NativeBridge.keepScreenOn(false);
    ScreenMode.restore();
    super.dispose();
  }

  void _onPip() {
    if (mounted) setState(() {});
  }

  // ---- Reproducción ---------------------------------------------------------

  Future<void> _open(int i) async {
    final gen = ++_gen;
    _retryTimer?.cancel();
    final old = _vc;
    _vc = null;
    old?.removeListener(_onTick);
    // Fuente iframe: se abre la página dentro de un WebView.
    if (_links[i].isIframe) {
      _hideTimer?.cancel();
      if (mounted) {
        setState(() {
          _index = i;
          _starting = false;
          _error = null;
          _webLoading = true;
          _web = _buildWeb(_links[i]);
        });
      }
      await old?.dispose();
      return;
    }
    if (mounted) {
      setState(() {
        _index = i;
        _starting = true;
        _error = null;
        _web = null;
        _webLoading = false;
      });
    }
    await old?.dispose();
    if (_disposed || gen != _gen) return;

    final s = _links[i];
    final headers = <String, String>{
      if (s.referer != null) 'Referer': s.referer!,
      if (s.userAgent != null) 'User-Agent': s.userAgent!,
    };
    final c = VideoPlayerController.networkUrl(
      Uri.parse(s.url),
      formatHint: VideoFormat.hls,
      httpHeaders: headers,
      videoPlayerOptions: VideoPlayerOptions(allowBackgroundPlayback: true),
    );
    try {
      await c.initialize();
      if (_disposed || gen != _gen) {
        await c.dispose();
        return;
      }
      c.addListener(_onTick);
      _vc = c;
      await c.play();
      if (_disposed || gen != _gen) return;
      _retries = 0;
      setState(() => _starting = false);
      _showControls();
    } catch (_) {
      if (identical(_vc, c)) {
        c.removeListener(_onTick);
        _vc = null;
      }
      await c.dispose();
      if (_disposed || gen != _gen) return;
      _fail('No se pudo reproducir esta fuente.');
    }
  }

  void _onTick() {
    final v = _vc?.value;
    if (v == null || !mounted) return;
    if (v.hasError && _error == null) {
      _fail(v.errorDescription ?? 'Error de reproducción.');
      return;
    }
    setState(() {});
  }

  void _fail(String msg) {
    if (!mounted) return;
    setState(() {
      _error = msg;
      _starting = false;
    });
    // Los directos se cortan a veces: se reintenta solo unas veces.
    if (_retries < 3 && !_casting) {
      _retries++;
      _retryTimer?.cancel();
      _retryTimer = Timer(const Duration(seconds: 3), () {
        if (mounted && !_casting) _open(_index);
      });
    }
  }

  Future<void> _togglePlay() async {
    if (_casting) {
      if (_remotePlaying) {
        await CastService.pause();
      } else {
        await CastService.play();
      }
      if (mounted) setState(() => _remotePlaying = !_remotePlaying);
      return;
    }
    final c = _vc;
    if (c == null) return;
    if (c.value.isPlaying) {
      await c.pause();
    } else {
      await c.play();
    }
    _showControls();
  }

  bool get _behindLive {
    final v = _vc?.value;
    if (v == null || !v.isInitialized) return false;
    return (v.duration - v.position).inSeconds > 12;
  }

  Future<void> _goLive() async {
    final c = _vc;
    if (c == null) return;
    await c.seekTo(c.value.duration);
    await c.play();
    _showControls();
  }

  /// WebView de una fuente iframe. Se bloquean las redirecciones a otros sitios
  /// (publicidad): solo se navega dentro del mismo sitio del iframe.
  WebViewController _buildWeb(StreamSource s) {
    final uri = Uri.parse(s.url);
    final embed = s.embed;
    // Con código iframe, la página base es el Referer (si hay) o el sitio del propio
    // iframe: así el embed ve un «padre» como en la web original.
    final base = (s.referer != null ? Uri.tryParse(s.referer!) : null) ?? uri;
    final allowedHosts = <String>{uri.host, if (base.host.isNotEmpty) base.host};
    final c = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.black)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) {
            if (mounted) setState(() => _webLoading = true);
          },
          onPageFinished: (_) {
            if (mounted) setState(() => _webLoading = false);
          },
          onNavigationRequest: (req) {
            if (!req.isMainFrame) return NavigationDecision.navigate;
            final u = Uri.tryParse(req.url);
            if (u == null) return NavigationDecision.prevent;
            if (u.scheme == 'about') return NavigationDecision.navigate;
            final web = u.scheme == 'http' || u.scheme == 'https';
            return web && allowedHosts.any((h) => _sameSite(u.host, h))
                ? NavigationDecision.navigate
                : NavigationDecision.prevent;
          },
          onWebResourceError: (e) {
            if ((e.isForMainFrame ?? false) && mounted) {
              setState(() {
                _webLoading = false;
                _error = 'No se pudo cargar la página.';
              });
            }
          },
        ),
      );
    if (s.userAgent != null) c.setUserAgent(s.userAgent);
    if (embed != null) {
      c.loadHtmlString(_embedPage(embed), baseUrl: '${base.scheme}://${base.host}/');
    } else {
      c.loadRequest(uri, headers: {if (s.referer != null) 'Referer': s.referer!});
    }
    return c;
  }

  /// Página mínima que muestra el iframe del panel a pantalla completa.
  String _embedPage(String iframe) {
    return '<!DOCTYPE html><html><head>'
        '<meta name="viewport" content="width=device-width, initial-scale=1">'
        '<style>html,body{margin:0;padding:0;height:100%;background:#000;overflow:hidden}'
        'iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style>'
        '</head><body>$iframe</body></html>';
  }

  /// Mismo sitio = mismo dominio base (www.x.com y cdn.x.com cuentan igual).
  bool _sameSite(String a, String b) {
    String root(String h) {
      final p = h.toLowerCase().split('.');
      return p.length <= 2
          ? h.toLowerCase()
          : p.sublist(p.length - 2).join('.');
    }

    return root(a) == root(b);
  }

  Future<void> _selectSource(int i) async {
    if (i == _index && _error == null) return;
    // Un iframe no se puede transmitir: se corta el Chromecast y se abre la página.
    if (_casting && _links[i].isIframe) {
      await CastService.stop();
      if (!mounted) return;
      setState(() => _casting = false);
      _retries = 0;
      await _open(i);
      return;
    }
    if (_casting) {
      setState(() => _index = i);
      try {
        await CastService.load(_links[i].url);
      } catch (_) {
        _snack('No se pudo cambiar la fuente en el Chromecast.');
      }
      return;
    }
    _retries = 0;
    await _open(i);
  }

  // ---- Controles ------------------------------------------------------------

  void _showControls() {
    if (!mounted) return;
    setState(() => _controls = true);
    _hideTimer?.cancel();
    _hideTimer = Timer(const Duration(seconds: 4), () {
      if (mounted && (_vc?.value.isPlaying ?? false)) {
        setState(() => _controls = false);
      }
    });
  }

  void _toggleControls() {
    if (_controls) {
      _hideTimer?.cancel();
      setState(() => _controls = false);
    } else {
      _showControls();
    }
  }

  void _snack(String m) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), behavior: SnackBarBehavior.floating),
    );
  }

  Future<void> _pip() async {
    final ok = await NativeBridge.enterPip();
    if (!ok) _snack('Imagen en imagen no está disponible en este dispositivo.');
  }

  // ---- Chromecast -----------------------------------------------------------

  Future<void> _castSheet() async {
    if (!CastService.ready) {
      _snack('Chromecast no está disponible en este dispositivo.');
      return;
    }
    CastService.startDiscovery();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xff1a1d23),
      constraints: const BoxConstraints(maxWidth: 520),
      builder: (sheetCtx) {
        final maxH = MediaQuery.of(sheetCtx).size.height * 0.8;
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxHeight: maxH),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 12, 8, 8),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsets.fromLTRB(12, 0, 12, 8),
                    child: Text(
                      'Transmitir a',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  if (_casting)
                    ListTile(
                      leading: const Icon(
                        Icons.cast_connected,
                        color: Pal.accent,
                      ),
                      title: Text(
                        'Transmitiendo a $_castName',
                        style: const TextStyle(color: Colors.white),
                      ),
                      trailing: TextButton(
                        onPressed: () {
                          Navigator.of(sheetCtx).pop();
                          _stopCast();
                        },
                        child: const Text('Detener'),
                      ),
                    ),
                  Flexible(
                    child: StreamBuilder<List<GoogleCastDevice>>(
                      stream: CastService.devices,
                      builder: (context, snap) {
                        final devices = snap.data ?? const <GoogleCastDevice>[];
                        if (devices.isEmpty) {
                          return const Padding(
                            padding: EdgeInsets.all(20),
                            child: Row(
                              children: [
                                SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: Colors.white70,
                                  ),
                                ),
                                SizedBox(width: 14),
                                Expanded(
                                  child: Text(
                                    'Buscando dispositivos en tu red Wi-Fi…',
                                    style: TextStyle(color: Colors.white70),
                                  ),
                                ),
                              ],
                            ),
                          );
                        }
                        return ListView(
                          shrinkWrap: true,
                          children: [
                            for (final d in devices)
                              ListTile(
                                leading: const Icon(
                                  Icons.cast,
                                  color: Colors.white70,
                                ),
                                title: Text(
                                  d.friendlyName,
                                  style: const TextStyle(color: Colors.white),
                                ),
                                onTap: () {
                                  Navigator.of(sheetCtx).pop();
                                  _startCast(d);
                                },
                              ),
                          ],
                        );
                      },
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Future<void> _startCast(GoogleCastDevice d) async {
    final src = _src;
    if (src.needsHeaders) {
      _snack(
        'Esta fuente usa Referer o User-Agent; el Chromecast podría no poder reproducirla.',
      );
    }
    try {
      await CastService.start(d, src.url);
      await _vc?.pause();
      if (!mounted) return;
      setState(() {
        _casting = true;
        _castName = d.friendlyName;
        _remotePlaying = true;
        _controls = true;
      });
    } catch (e) {
      _snack('No se pudo transmitir: $e');
    }
  }

  Future<void> _stopCast() async {
    await CastService.stop();
    if (!mounted) return;
    setState(() => _casting = false);
    await _vc?.play();
  }

  // ---- Interfaz -------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final pip = NativeBridge.inPip.value;
    final v = _vc?.value;
    final buffering =
        !_casting && _error == null && (_starting || (v?.isBuffering ?? false));
    final Widget player = (_isIframe && _web != null)
        ? _iframePlayer(pip)
        : GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: pip ? null : _toggleControls,
            child: Stack(
              fit: StackFit.expand,
              children: [
                _video(),
                if (buffering)
                  const Center(
                    child: SizedBox(
                      width: 34,
                      height: 34,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.5,
                        color: Colors.white70,
                      ),
                    ),
                  ),
                if (_error != null && !_casting && !pip) _errorView(),
                if (_casting && !pip) _castView(),
                if (!pip) _controlsLayer(),
              ],
            ),
          );
    // Imagen en imagen o pantalla completa horizontal: solo el video.
    if (pip || _landscape) {
      return Scaffold(backgroundColor: Colors.black, body: player);
    }
    // Vertical (por defecto): video arriba e información y fuentes debajo.
    return Scaffold(
      backgroundColor: const Color(0xFF0B0F14),
      body: Column(
        children: [
          SafeArea(
            bottom: false,
            child: AspectRatio(
              aspectRatio: 16 / 9,
              child: ColoredBox(color: Colors.black, child: player),
            ),
          ),
          Expanded(child: _infoPanel()),
        ],
      ),
    );
  }

  /// Página web (iframe) a pantalla del reproductor, sin la capa de controles
  /// (taparía los botones de la propia página): solo volver y girar.
  Widget _iframePlayer(bool pip) {
    Widget round(IconData icon, String tip, VoidCallback onTap) {
      return Material(
        color: Colors.black54,
        shape: const CircleBorder(),
        child: IconButton(
          tooltip: tip,
          visualDensity: VisualDensity.compact,
          iconSize: 22,
          color: Colors.white,
          onPressed: onTap,
          icon: Icon(icon),
        ),
      );
    }

    return Stack(
      fit: StackFit.expand,
      children: [
        WebViewWidget(controller: _web!),
        if (_webLoading && _error == null)
          const Center(
            child: SizedBox(
              width: 34,
              height: 34,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: Colors.white70,
              ),
            ),
          ),
        if (_error != null && !pip)
          ColoredBox(color: Colors.black, child: _errorView()),
        if (!pip)
          Positioned.fill(
            child: SafeArea(
              child: Align(
                alignment: Alignment.topCenter,
                child: Padding(
                  padding: const EdgeInsets.all(4),
                  child: Row(
                    children: [
                      round(
                        Icons.arrow_back,
                        'Volver',
                        () => Navigator.of(context).maybePop(),
                      ),
                      const Spacer(),
                      round(
                        _landscape
                            ? Icons.fullscreen_exit_rounded
                            : Icons.fullscreen_rounded,
                        _landscape
                            ? 'Salir de pantalla completa'
                            : 'Pantalla completa',
                        _toggleOrientation,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _infoPanel() {
    final e = widget.event;
    final k = e.kickoff;
    final sub = [
      e.league,
      if (k != null && !e.timeTbc) '${fmtDm(k)} · ${fmtHm(k)}',
    ].where((x) => x.isNotEmpty).join(' · ');
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            e.title,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.w700,
            ),
          ),
          if (sub.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              sub,
              style: const TextStyle(color: Colors.white60, fontSize: 13),
            ),
          ],
          if (_links.length > 1) ...[
            const SizedBox(height: 18),
            const Text(
              'OPCIONES',
              style: TextStyle(
                color: Colors.white54,
                fontSize: 11,
                fontWeight: FontWeight.w700,
                letterSpacing: 1,
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (var i = 0; i < _links.length; i++)
                  ChoiceChip(
                    label: Text(_links[i].label),
                    avatar: _links[i].isIframe
                        ? const Icon(Icons.language_rounded, size: 16)
                        : null,
                    selected: i == _index,
                    showCheckmark: false,
                    selectedColor: Pal.accent.withValues(alpha: 0.25),
                    backgroundColor: Colors.white10,
                    side: BorderSide(
                      color: i == _index ? Pal.accent : Colors.white24,
                    ),
                    labelStyle: TextStyle(
                      color: i == _index ? Pal.accent : Colors.white70,
                      fontWeight: FontWeight.w600,
                    ),
                    onSelected: (_) => _selectSource(i),
                  ),
              ],
            ),
          ],
          const SizedBox(height: 18),
          OutlinedButton.icon(
            onPressed: _toggleOrientation,
            icon: const Icon(Icons.fullscreen_rounded),
            label: const Text('Pantalla completa (horizontal)'),
            style: OutlinedButton.styleFrom(
              foregroundColor: Colors.white,
              side: const BorderSide(color: Colors.white24),
            ),
          ),
        ],
      ),
    );
  }

  Widget _video() {
    final c = _vc;
    if (c == null || !c.value.isInitialized) return const SizedBox.shrink();
    final ar = c.value.aspectRatio > 0 ? c.value.aspectRatio : 16 / 9;
    return Center(
      child: AspectRatio(aspectRatio: ar, child: VideoPlayer(c)),
    );
  }

  Widget _errorView() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline, color: Colors.white70, size: 40),
          const SizedBox(height: 8),
          Text(
            _error ?? '',
            style: const TextStyle(color: Colors.white70),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextButton(
                onPressed: () {
                  _retries = 0;
                  _open(_index);
                },
                child: const Text('Reintentar'),
              ),
              if (_links.length > 1)
                TextButton(
                  onPressed: () {
                    _retries = 0;
                    _open((_index + 1) % _links.length);
                  },
                  child: const Text('Otra fuente'),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _castView() {
    return Container(
      color: const Color(0xe6000000),
      alignment: Alignment.center,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cast_connected, color: Colors.white, size: 48),
          const SizedBox(height: 10),
          Text(
            'Transmitiendo a $_castName',
            style: const TextStyle(color: Colors.white, fontSize: 16),
          ),
          const SizedBox(height: 10),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                iconSize: 44,
                color: Colors.white,
                onPressed: _togglePlay,
                icon: Icon(
                  _remotePlaying
                      ? Icons.pause_circle_outline
                      : Icons.play_circle_outline,
                ),
              ),
              const SizedBox(width: 12),
              TextButton(onPressed: _stopCast, child: const Text('Detener')),
            ],
          ),
        ],
      ),
    );
  }

  Widget _controlsLayer() {
    final v = _vc?.value;
    final playing = _casting ? _remotePlaying : (v?.isPlaying ?? false);
    final showCenter = !_casting && _error == null && !_starting;
    return IgnorePointer(
      ignoring: !_controls,
      child: AnimatedOpacity(
        opacity: _controls ? 1 : 0,
        duration: const Duration(milliseconds: 180),
        child: Stack(
          fit: StackFit.expand,
          children: [
            const Positioned(
              top: 0,
              left: 0,
              right: 0,
              height: 90,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Color(0xb3000000), Color(0x00000000)],
                  ),
                ),
              ),
            ),
            const Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              height: 70,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.bottomCenter,
                    end: Alignment.topCenter,
                    colors: [Color(0xb3000000), Color(0x00000000)],
                  ),
                ),
              ),
            ),
            if (showCenter)
              Center(
                child: IconButton(
                  iconSize: 64,
                  color: Colors.white,
                  onPressed: _togglePlay,
                  icon: Icon(
                    playing ? Icons.pause_rounded : Icons.play_arrow_rounded,
                  ),
                ),
              ),
            SafeArea(
              child: Column(
                children: [_topBar(), const Spacer(), _bottomBar()],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _topBar() {
    final multi = _links.length > 1;
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 6, 4, 0),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Volver',
            onPressed: () => Navigator.of(context).maybePop(),
            icon: const Icon(Icons.arrow_back, color: Colors.white),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  widget.event.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w600,
                    fontSize: 15,
                  ),
                ),
                Text(
                  _links.length > 1 ? _src.label : widget.event.league,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white60, fontSize: 12),
                ),
              ],
            ),
          ),
          if (multi)
            PopupMenuButton<int>(
              tooltip: 'Fuentes',
              icon: const Icon(Icons.layers_outlined, color: Colors.white),
              onSelected: (i) {
                _selectSource(i);
              },
              itemBuilder: (_) => [
                for (var i = 0; i < _links.length; i++)
                  CheckedPopupMenuItem<int>(
                    value: i,
                    checked: i == _index,
                    child: Text(_links[i].label),
                  ),
              ],
            ),
          IconButton(
            tooltip: 'Transmitir',
            onPressed: _castSheet,
            icon: Icon(
              _casting ? Icons.cast_connected : Icons.cast,
              color: _casting ? Pal.accent : Colors.white,
            ),
          ),
          IconButton(
            tooltip: 'Imagen en imagen',
            onPressed: _pip,
            icon: const Icon(
              Icons.picture_in_picture_alt_outlined,
              color: Colors.white,
            ),
          ),
        ],
      ),
    );
  }

  Widget _bottomBar() {
    final behind = _behindLive && !_casting;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Row(
        children: [
          GestureDetector(
            onTap: behind ? _goLive : null,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
              decoration: BoxDecoration(
                color: behind ? Colors.white24 : Pal.live,
                borderRadius: BorderRadius.circular(4),
              ),
              child: Text(
                behind ? 'IR AL DIRECTO' : 'EN VIVO',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.8,
                ),
              ),
            ),
          ),
          const Spacer(),
          GestureDetector(
            onTap: _toggleOrientation,
            child: Icon(
              _landscape
                  ? Icons.fullscreen_exit_rounded
                  : Icons.fullscreen_rounded,
              color: Colors.white,
              size: 26,
            ),
          ),
        ],
      ),
    );
  }
}
