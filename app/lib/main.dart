import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show FontFeature;

import 'package:flutter/foundation.dart' show ValueListenable;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'cast_service.dart';
import 'live_player.dart';
import 'models.dart';
import 'native_bridge.dart';
import 'theme.dart';

export 'models.dart';

/// Versión visible de la app (mantener igual que pubspec.yaml).
const String liveoVersion = '1.0.17';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await CastService.init();
  runApp(const LiveoApp());
}

bool _isDark(BuildContext context) =>
    Theme.of(context).brightness == Brightness.dark;

Color _cardColor(BuildContext context) =>
    _isDark(context) ? const Color(0xff161b22) : Colors.white;

Color _muted(BuildContext context) =>
    Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.6);

// ---------------------------------------------------------------------------
// Fondo y texto con degradado (solo se usan en la presentación inicial)
// ---------------------------------------------------------------------------

/// Fondo animado con manchas de color que se mueven lentamente.
class AuroraBackground extends StatefulWidget {
  const AuroraBackground({super.key, required this.child});

  final Widget child;

  @override
  State<AuroraBackground> createState() => _AuroraBackgroundState();
}

class _AuroraBackgroundState extends State<AuroraBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 20),
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Widget _blob(Alignment a, Color c, double size, double alpha) {
    return Align(
      alignment: a,
      child: IgnorePointer(
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: RadialGradient(
              colors: [c.withValues(alpha: alpha), c.withValues(alpha: 0)],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final dark = _isDark(context);
    return Stack(
      fit: StackFit.expand,
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: dark
                  ? const [
                      Color(0xff070b14),
                      Color(0xff0c1230),
                      Color(0xff0a0f1e),
                    ]
                  : const [
                      Color(0xfff4f7ff),
                      Color(0xffeaf0ff),
                      Color(0xfff6fff2),
                    ],
            ),
          ),
        ),
        RepaintBoundary(
          child: AnimatedBuilder(
            animation: _c,
            builder: (context, _) {
              final t = _c.value * 2 * math.pi;
              final a = dark ? 0.34 : 0.22;
              return Stack(
                fit: StackFit.expand,
                children: [
                  _blob(
                    Alignment(math.cos(t) * 0.8, -0.75 + math.sin(t) * 0.2),
                    Pal.violet,
                    360,
                    a,
                  ),
                  _blob(
                    Alignment(-0.85 + math.sin(t) * 0.2, 0.25 + math.cos(t) * 0.3),
                    Pal.cyan,
                    320,
                    a * 0.85,
                  ),
                  _blob(
                    Alignment(0.9 - math.cos(t) * 0.2, 0.95 + math.sin(t) * 0.1),
                    Pal.lime,
                    300,
                    a * 0.7,
                  ),
                ],
              );
            },
          ),
        ),
        widget.child,
      ],
    );
  }
}

class GradientText extends StatelessWidget {
  const GradientText(
    this.text, {
    super.key,
    required this.style,
    this.gradient = Pal.brand,
  });

  final String text;
  final TextStyle style;
  final Gradient gradient;

  @override
  Widget build(BuildContext context) {
    return ShaderMask(
      blendMode: BlendMode.srcIn,
      shaderCallback: (r) =>
          gradient.createShader(Rect.fromLTWH(0, 0, r.width, r.height)),
      child: Text(text, style: style.copyWith(color: Colors.white)),
    );
  }
}

// ---------------------------------------------------------------------------
// Logo de Liveo (anillo de señal + play + punto "en vivo")
// ---------------------------------------------------------------------------

/// Dibuja el logo en un lienzo de 100 x 100 unidades. Es la misma geometría
/// que usa app/branding/generate_icons.py para el icono de la app.
class LogoPainter extends CustomPainter {
  const LogoPainter({
    this.ring = 1,
    this.play = 1,
    this.dot = 1,
    this.glow = 0,
  });

  /// 0..1: cuánto del anillo está dibujado.
  final double ring;

  /// Escala del triángulo de play.
  final double play;

  /// Escala del punto en vivo.
  final double dot;

  /// 0..1: intensidad del resplandor detrás del anillo.
  final double glow;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / 100, size.height / 100);
    const c = Offset(50, 50);

    if (glow > 0) {
      canvas.drawCircle(
        c,
        46,
        Paint()
          ..shader = RadialGradient(
            colors: [
              Pal.cyan.withValues(alpha: 0.30 * glow),
              Pal.cyan.withValues(alpha: 0),
            ],
          ).createShader(Rect.fromCircle(center: c, radius: 46)),
      );
    }

    // Anillo: arco de 300° con hueco arriba a la derecha.
    final rect = Rect.fromCircle(center: c, radius: 31);
    if (ring > 0) {
      final ringPaint = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 7
        ..strokeCap = StrokeCap.round
        ..shader = const SweepGradient(
          startAngle: 0,
          endAngle: 5 * math.pi / 3,
          colors: [Pal.lime, Pal.cyan, Pal.violet],
          stops: [0, 0.5, 1],
          transform: GradientRotation(-math.pi / 6),
        ).createShader(rect);
      canvas.drawArc(rect, -math.pi / 6, 5 * math.pi / 3 * ring, false, ringPaint);
    }

    // Triángulo de play con esquinas redondeadas.
    if (play > 0) {
      canvas.save();
      canvas.translate(51, 50);
      canvas.scale(play);
      canvas.translate(-51, -50);
      final path = Path()
        ..moveTo(43, 36)
        ..lineTo(43, 64)
        ..lineTo(67, 50)
        ..close();
      final shader = const LinearGradient(colors: [Pal.lime, Pal.cyan])
          .createShader(const Rect.fromLTWH(41, 36, 28, 28));
      canvas.drawPath(path, Paint()..shader = shader);
      canvas.drawPath(
        path,
        Paint()
          ..shader = shader
          ..style = PaintingStyle.stroke
          ..strokeWidth = 5
          ..strokeJoin = StrokeJoin.round,
      );
      canvas.restore();
    }

    // Punto "en vivo".
    if (dot > 0) {
      final d = Offset(
        50 + 31 * math.cos(5 * math.pi / 3),
        50 + 31 * math.sin(5 * math.pi / 3),
      );
      canvas.drawCircle(
        d,
        5.5 * dot * 1.4,
        Paint()
          ..color = Pal.pink.withValues(alpha: 0.55)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 4),
      );
      canvas.drawCircle(
        d,
        5.5 * dot,
        Paint()
          ..shader = const LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Pal.pink, Pal.orange],
          ).createShader(Rect.fromCircle(center: d, radius: 5.5)),
      );
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(LogoPainter old) =>
      old.ring != ring ||
      old.play != play ||
      old.dot != dot ||
      old.glow != glow;
}

class LiveoLogo extends StatelessWidget {
  const LiveoLogo({
    super.key,
    this.size = 48,
    this.ring = 1,
    this.play = 1,
    this.dot = 1,
    this.glow = 0,
  });

  final double size;
  final double ring;
  final double play;
  final double dot;
  final double glow;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: LogoPainter(ring: ring, play: play, dot: dot, glow: glow),
      ),
    );
  }
}

/// El logo sobre un recuadro redondeado, como el icono de la app.
class LogoTile extends StatelessWidget {
  const LogoTile({super.key, this.size = 46});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      padding: EdgeInsets.all(size * 0.08),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.28),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xff2a2470), Color(0xff0b1230), Color(0xff08202c)],
        ),
        border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
        boxShadow: [
          BoxShadow(
            color: Pal.violet.withValues(alpha: 0.35),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: LiveoLogo(size: size * 0.84),
    );
  }
}

// ---------------------------------------------------------------------------
// Presentación al abrir la app
// ---------------------------------------------------------------------------

double _interval(double t, double a, double b, Curve curve) =>
    curve.transform(((t - a) / (b - a)).clamp(0.0, 1.0).toDouble());

/// Ondas de señal que salen del punto en vivo del logo.
class _WavesPainter extends CustomPainter {
  const _WavesPainter({required this.t, required this.unit, required this.strength});

  final double t;
  final double unit;
  final double strength;

  @override
  void paint(Canvas canvas, Size size) {
    if (strength <= 0) return;
    final origin = Offset(
      size.width / 2 + 15.5 * unit,
      size.height / 2 - 26.85 * unit,
    );
    for (var k = 0; k < 2; k++) {
      final p = (t + k * 0.5) % 1.0;
      canvas.drawCircle(
        origin,
        (6 + p * 34) * unit,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.2
          ..color = Pal.pink.withValues(alpha: (1 - p) * 0.5 * strength),
      );
    }
  }

  @override
  bool shouldRepaint(_WavesPainter old) =>
      old.t != t || old.unit != unit || old.strength != strength;
}

/// Cargador sin texto: barras de ecualizador que laten.
class _EqLoader extends StatelessWidget {
  const _EqLoader({required this.t});

  final double t;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 38,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          for (var i = 0; i < 5; i++)
            Container(
              width: 6,
              height: 10 + 26 * (0.5 + 0.5 * math.sin(2 * math.pi * (t + i * 0.17))),
              margin: const EdgeInsets.symmetric(horizontal: 3),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(3),
                color: Color.lerp(Pal.lime, Pal.violet, i / 4),
                boxShadow: [
                  BoxShadow(
                    color: Color.lerp(Pal.lime, Pal.violet, i / 4)!
                        .withValues(alpha: 0.45),
                    blurRadius: 8,
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, required this.onFinished});

  final VoidCallback onFinished;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with TickerProviderStateMixin {
  late final AnimationController _intro = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 2400),
  );
  late final AnimationController _loop = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  )..repeat();

  @override
  void initState() {
    super.initState();
    _intro.forward().whenComplete(() async {
      await Future<void>.delayed(const Duration(milliseconds: 450));
      if (mounted) widget.onFinished();
    });
  }

  @override
  void dispose() {
    _intro.dispose();
    _loop.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    const logo = 168.0;
    return Theme(
      data: ThemeData.dark(useMaterial3: true),
      child: AuroraBackground(
        child: Material(
          type: MaterialType.transparency,
          child: AnimatedBuilder(
            animation: Listenable.merge([_intro, _loop]),
            builder: (context, _) {
              final t = _intro.value;
              final ring = _interval(t, 0.05, 0.55, Curves.easeInOutCubic);
              final play = _interval(t, 0.35, 0.65, Curves.elasticOut);
              final dot = _interval(t, 0.55, 0.72, Curves.easeOutBack);
              final glow = _interval(t, 0.10, 0.80, Curves.easeOut);
              final word = _interval(t, 0.50, 0.85, Curves.easeOutCubic);
              final loader = _interval(t, 0.70, 0.95, Curves.easeOut);
              return Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    SizedBox(
                      width: logo * 1.8,
                      height: logo * 1.8,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          Positioned.fill(
                            child: CustomPaint(
                              painter: _WavesPainter(
                                t: _loop.value,
                                unit: logo / 100,
                                strength: dot.clamp(0.0, 1.0).toDouble(),
                              ),
                            ),
                          ),
                          LiveoLogo(
                            size: logo,
                            ring: ring,
                            play: play,
                            dot: dot,
                            glow: glow,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),
                    Opacity(
                      opacity: word,
                      child: Transform.translate(
                        offset: Offset(0, 14 * (1 - word)),
                        child: GradientText(
                          'LIVEO',
                          style: TextStyle(
                            fontSize: 34,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 14 - 10 * word,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 40),
                    Opacity(opacity: loader, child: _EqLoader(t: _loop.value)),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Muestra la presentación y después hace un fundido hacia la app.
class SplashGate extends StatefulWidget {
  const SplashGate({super.key, required this.child});

  final Widget child;

  @override
  State<SplashGate> createState() => _SplashGateState();
}

class _SplashGateState extends State<SplashGate> {
  bool _done = false;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 650),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      child: _done
          ? KeyedSubtree(key: const ValueKey('home'), child: widget.child)
          : SplashScreen(
              key: const ValueKey('splash'),
              onFinished: () {
                if (mounted) setState(() => _done = true);
              },
            ),
    );
  }
}

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------

ThemeData _buildTheme(Brightness b) {
  final dark = b == Brightness.dark;
  final bg = dark ? const Color(0xff0e1116) : const Color(0xfff4f5f7);
  return ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: Pal.accent, brightness: b),
    scaffoldBackgroundColor: bg,
    appBarTheme: AppBarTheme(
      backgroundColor: bg,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
    ),
  );
}

class LiveoApp extends StatefulWidget {
  const LiveoApp({super.key});

  @override
  State<LiveoApp> createState() => _LiveoAppState();
}

class _LiveoAppState extends State<LiveoApp> {
  ThemeMode mode = ThemeMode.dark;

  @override
  void initState() {
    super.initState();
    _loadTheme();
  }

  Future<void> _loadTheme() async {
    final p = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      mode = (p.getBool('darkMode') ?? true) ? ThemeMode.dark : ThemeMode.light;
    });
  }

  Future<void> _toggleTheme(bool dark) async {
    setState(() {
      mode = dark ? ThemeMode.dark : ThemeMode.light;
    });
    final p = await SharedPreferences.getInstance();
    await p.setBool('darkMode', dark);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Liveo',
      debugShowCheckedModeBanner: false,
      themeMode: mode,
      theme: _buildTheme(Brightness.light),
      darkTheme: _buildTheme(Brightness.dark),
      home: SplashGate(
        child: HomePage(dark: mode == ThemeMode.dark, onTheme: _toggleTheme),
      ),
    );
  }
}

/// Abre el reproductor integrado del evento (enlaces m3u8 cargados en el panel).
Future<void> openEvent(BuildContext context, LiveEvent e) async {
  if (e.links.isEmpty) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Este evento todavía no tiene señal.')),
    );
    return;
  }
  await Navigator.of(context).push(
    MaterialPageRoute<void>(builder: (_) => LivePlayerPage(event: e)),
  );
}

class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.dark, required this.onTheme});

  final bool dark;
  final ValueChanged<bool> onTheme;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  int tab = 0;
  final ValueNotifier<int> _refreshHome = ValueNotifier<int>(0);
  final ValueNotifier<int> _refreshAgenda = ValueNotifier<int>(0);

  @override
  void initState() {
    super.initState();
    _startClock();
    FavStore.instance.load();
  }

  @override
  void dispose() {
    _refreshHome.dispose();
    _refreshAgenda.dispose();
    super.dispose();
  }

  void _reload() {
    if (tab == 0) {
      _refreshHome.value++;
    } else if (tab == 1) {
      _refreshAgenda.value++;
    }
  }

  @override
  Widget build(BuildContext context) {
    const titles = ['Liveo', 'Agenda', 'Ajustes'];
    return Scaffold(
      extendBody: true,
      appBar: AppBar(
        titleSpacing: 16,
        title: tab == 0
            ? const Row(
                children: [
                  LiveoLogo(size: 26),
                  SizedBox(width: 10),
                  Text(
                    'LIVEO',
                    style: TextStyle(
                      fontWeight: FontWeight.w800,
                      letterSpacing: 2.5,
                    ),
                  ),
                ],
              )
            : Text(
                titles[tab],
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
        actions: [
          IconButton(
            tooltip: 'Favoritos',
            onPressed: () => Navigator.of(context)
                .push(_fadeRoute(const FavoritesPage())),
            icon: const Icon(Icons.star_rounded),
          ),
          IconButton(
            tooltip: 'Actualizar',
            onPressed: _reload,
            icon: const Icon(Icons.refresh_rounded),
          ),
          IconButton(
            tooltip: 'Buscar',
            onPressed: () =>
                Navigator.of(context).push(_fadeRoute(const SearchPage())),
            icon: const Icon(Icons.search_rounded),
          ),
          const SizedBox(width: 4),
        ],
      ),
      body: IndexedStack(
        index: tab,
        children: [
          EventsHome(refresh: _refreshHome),
          AgendaTab(refresh: _refreshAgenda),
          SettingsPage(dark: widget.dark, onTheme: widget.onTheme),
        ],
      ),
      bottomNavigationBar: _FloatingNav(
        index: tab,
        onChanged: (i) => setState(() => tab = i),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Utilidades: reloj, transiciones, texto sin acentos
// ---------------------------------------------------------------------------

/// Reloj de 1 segundo para las cuentas atrás (solo reconstruye sus textos).
final ValueNotifier<int> _clock = ValueNotifier<int>(0);
Timer? _clockTimer;

void _startClock() {
  _clockTimer ??= Timer.periodic(
    const Duration(seconds: 1),
    (t) => _clock.value++,
  );
}

Route<void> _fadeRoute(Widget page) {
  return PageRouteBuilder<void>(
    transitionDuration: const Duration(milliseconds: 220),
    reverseTransitionDuration: const Duration(milliseconds: 180),
    pageBuilder: (context, animation, secondary) => page,
    transitionsBuilder: (context, animation, secondary, child) {
      final curved = CurvedAnimation(parent: animation, curve: Curves.easeOut);
      return FadeTransition(
        opacity: curved,
        child: SlideTransition(
          position: Tween<Offset>(
            begin: const Offset(0, 0.03),
            end: Offset.zero,
          ).animate(curved),
          child: child,
        ),
      );
    },
  );
}

const String _fromChars = 'áàäâãåéèëêíìïîóòöôõúùüûñçÁÀÄÂÃÅÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇ';
const String _toChars = 'aaaaaaeeeeiiiiooooouuuuncAAAAAAEEEEIIIIOOOOOUUUUNC';

final Map<String, String> _foldMap = () {
  final m = <String, String>{};
  for (var i = 0; i < _fromChars.length && i < _toChars.length; i++) {
    m[_fromChars[i]] = _toChars[i];
  }
  return m;
}();

/// Minúsculas y sin acentos, manteniendo la longitud (para poder resaltar).
String _fold(String s) {
  final b = StringBuffer();
  for (final ch in s.split('')) {
    b.write(_foldMap[ch] ?? ch);
  }
  return b.toString().toLowerCase();
}

String _p2(int n) => n.toString().padLeft(2, '0');

bool _sameDay(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;

const List<String> _weekdays = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
const List<String> _months = [
  'ene', 'feb', 'mar', 'abr', 'may', 'jun',
  'jul', 'ago', 'sep', 'oct', 'nov', 'dic',
];

String _dayText(DateTime d) =>
    '${_weekdays[d.weekday - 1]} ${d.day} ${_months[d.month - 1]}';

String _dayLabel(DateTime k) {
  final now = DateTime.now();
  if (_sameDay(k, now)) return 'Hoy';
  if (_sameDay(k, now.add(const Duration(days: 1)))) return 'Mañana';
  return fmtDm(k);
}

int _byKickoff(LiveEvent a, LiveEvent b) {
  final ta = a.kickoff;
  final tb = b.kickoff;
  if (ta == null && tb == null) return 0;
  if (ta == null) return 1;
  if (tb == null) return -1;
  return ta.compareTo(tb);
}

/// En vivo primero y después por hora de inicio.
int _liveFirst(LiveEvent a, LiveEvent b) {
  if (a.liveNow != b.liveNow) return a.liveNow ? -1 : 1;
  return _byKickoff(a, b);
}

// ---------------------------------------------------------------------------
// Datos compartidos y favoritos
// ---------------------------------------------------------------------------

/// Eventos cargados (en vivo + hoy + mañana). Los usan el inicio, el buscador
/// y los favoritos.
/// Días hacia adelante que carga el inicio (el servidor admite hasta 120).
const int _upcomingDays = 14;

class EventsStore {
  EventsStore._();

  static final EventsStore instance = EventsStore._();

  List<LiveEvent> all = const <LiveEvent>[];

  Future<List<LiveEvent>> load() async {
    Object? firstError;
    Future<List<LiveEvent>> safe(String path) async {
      try {
        return await LiveoApi.events(path);
      } catch (e) {
        firstError ??= e;
        return const <LiveEvent>[];
      }
    }

    final res = await Future.wait([
      safe('live'),
      safe('upcoming?days=$_upcomingDays'),
    ]);
    final byKey = <String, LiveEvent>{};
    for (final list in res) {
      for (final e in list) {
        final key =
            e.id.isEmpty ? '${e.home}|${e.away}|${e.kickoff}' : e.id;
        byKey.putIfAbsent(key, () => e);
      }
    }
    final merged = byKey.values.toList()..sort(_byKickoff);
    if (merged.isEmpty && firstError != null) {
      throw firstError!;
    }
    all = merged;
    return merged;
  }
}

/// Favoritos guardados en el teléfono (por id de evento).
class FavStore {
  FavStore._();

  static final FavStore instance = FavStore._();
  static const String _key = 'favEvents';

  final ValueNotifier<List<String>> ids =
      ValueNotifier<List<String>>(<String>[]);

  Future<void> load() async {
    final p = await SharedPreferences.getInstance();
    ids.value = List<String>.from(p.getStringList(_key) ?? const <String>[]);
  }

  Future<void> toggle(String id) async {
    if (id.isEmpty) return;
    final list = <String>[...ids.value];
    if (!list.remove(id)) {
      list.add(id);
      if (list.length > 200) list.removeAt(0);
    }
    ids.value = list;
    final p = await SharedPreferences.getInstance();
    await p.setStringList(_key, list);
  }
}

// ---------------------------------------------------------------------------
// Filtros del inicio
// ---------------------------------------------------------------------------

enum _Quick { all, live, today, tomorrow }

/// Lo que se muestra en el inicio: eventos en vivo y próximos.
List<LiveEvent> _homeEvents(List<LiveEvent> all) {
  final from = DateTime.now().subtract(const Duration(minutes: 10));
  final out = all.where((e) {
    if (e.liveNow) return true;
    final k = e.kickoff;
    return k == null || k.isAfter(from);
  }).toList()
    ..sort(_liveFirst);
  return out;
}

List<LiveEvent> _applyQuick(List<LiveEvent> src, _Quick q) {
  final now = DateTime.now();
  final tm = now.add(const Duration(days: 1));
  switch (q) {
    case _Quick.all:
      return src;
    case _Quick.live:
      return src.where((e) => e.liveNow).toList();
    case _Quick.today:
      return src.where((e) {
        final k = e.kickoff;
        return e.liveNow || (k != null && _sameDay(k, now));
      }).toList();
    case _Quick.tomorrow:
      return src.where((e) {
        final k = e.kickoff;
        return !e.liveNow && k != null && _sameDay(k, tm);
      }).toList();
  }
}

// ---------------------------------------------------------------------------
// Tarjeta de evento (sin escudos ni marcador)
// ---------------------------------------------------------------------------

/// Texto con las coincidencias de la búsqueda resaltadas.
class _Hl extends StatelessWidget {
  const _Hl(
    this.text,
    this.terms, {
    this.style,
    this.align = TextAlign.start,
    this.maxLines = 1,
  });

  final String text;
  final List<String> terms;
  final TextStyle? style;
  final TextAlign align;
  final int maxLines;

  @override
  Widget build(BuildContext context) {
    final f = _fold(text);
    if (terms.isEmpty || f.length != text.length) {
      return Text(
        text,
        style: style,
        textAlign: align,
        maxLines: maxLines,
        overflow: TextOverflow.ellipsis,
      );
    }
    final spans = <TextSpan>[];
    var i = 0;
    while (i < text.length) {
      var bestAt = -1;
      var bestLen = 0;
      for (final t in terms) {
        if (t.isEmpty) continue;
        final at = f.indexOf(t, i);
        if (at >= 0 &&
            (bestAt < 0 || at < bestAt || (at == bestAt && t.length > bestLen))) {
          bestAt = at;
          bestLen = t.length;
        }
      }
      if (bestAt < 0) {
        spans.add(TextSpan(text: text.substring(i)));
        break;
      }
      if (bestAt > i) {
        spans.add(TextSpan(text: text.substring(i, bestAt)));
      }
      spans.add(
        TextSpan(
          text: text.substring(bestAt, bestAt + bestLen),
          style: const TextStyle(
            color: Pal.accent,
            fontWeight: FontWeight.w800,
          ),
        ),
      );
      i = bestAt + bestLen;
    }
    return Text.rich(
      TextSpan(style: style, children: spans),
      textAlign: align,
      maxLines: maxLines,
      overflow: TextOverflow.ellipsis,
    );
  }
}

class _FavButton extends StatelessWidget {
  const _FavButton({required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<List<String>>(
      valueListenable: FavStore.instance.ids,
      builder: (context, ids, child) {
        final fav = ids.contains(id);
        return IconButton(
          tooltip: fav ? 'Quitar de favoritos' : 'Añadir a favoritos',
          visualDensity: VisualDensity.compact,
          padding: EdgeInsets.zero,
          constraints: const BoxConstraints.tightFor(width: 40, height: 32),
          iconSize: 21,
          onPressed: () => FavStore.instance.toggle(id),
          icon: Icon(
            fav ? Icons.star_rounded : Icons.star_border_rounded,
            color: fav ? Pal.accent : _muted(context),
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Tarjeta de evento: hora · deporte · escudos y rivales | cuenta regresiva
// ---------------------------------------------------------------------------

/// Ícono del deporte según el texto de deporte / competición.
IconData sportIcon(String sport, String league) {
  final s = _fold('$sport $league');
  bool any(List<String> keys) => keys.any(s.contains);
  if (any(['futbol americano', 'american football', 'nfl'])) {
    return Icons.sports_football_rounded;
  }
  if (any(['basquet', 'baloncesto', 'basket', 'nba'])) {
    return Icons.sports_basketball_rounded;
  }
  if (any(['tenis', 'tennis'])) return Icons.sports_tennis_rounded;
  if (any(['beisbol', 'baseball', 'mlb'])) return Icons.sports_baseball_rounded;
  if (any(['hockey', 'nhl'])) return Icons.sports_hockey_rounded;
  if (any(['rugby'])) return Icons.sports_rugby_rounded;
  if (any(['voley', 'volley'])) return Icons.sports_volleyball_rounded;
  if (any(['motociclismo', 'motogp', 'moto '])) return Icons.two_wheeler_rounded;
  if (any(['ciclismo', 'cycling'])) return Icons.directions_bike_rounded;
  if (any(['automovilismo', 'formula', 'rally', 'motorsport', 'nascar'])) {
    return Icons.sports_motorsports_rounded;
  }
  if (any(['mma', 'ufc'])) return Icons.sports_mma_rounded;
  if (any(['boxeo', 'boxing'])) return Icons.sports_martial_arts_rounded;
  if (any(['golf'])) return Icons.sports_golf_rounded;
  if (any(['futbol', 'soccer', 'football'])) return Icons.sports_soccer_rounded;
  return Icons.sports_rounded;
}

/// Escudo del equipo (URL del panel) o, si no hay, sus iniciales.
class _Crest extends StatelessWidget {
  const _Crest({required this.url, required this.name, this.size = 28});

  final String? url;
  final String name;
  final double size;

  Widget _initials() {
    final words =
        name.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    final txt = words.isEmpty
        ? '?'
        : (words.length == 1
                ? words.first.substring(0, math.min(2, words.first.length))
                : '${words.first[0]}${words[1][0]}')
            .toUpperCase();
    final hue = (name.hashCode.abs() % 360).toDouble();
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: HSLColor.fromAHSL(1, hue, 0.45, 0.4).toColor(),
      ),
      child: Text(
        txt,
        style: TextStyle(
          color: Colors.white,
          fontSize: size * 0.38,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final u = url;
    if (u == null || u.isEmpty) return _initials();
    return SizedBox(
      width: size,
      height: size,
      child: Image.network(
        u,
        fit: BoxFit.contain,
        errorBuilder: (context, error, stack) => _initials(),
        loadingBuilder: (context, child, progress) =>
            progress == null ? child : _initials(),
      ),
    );
  }
}

/// Una fila de la tarjeta: escudo + nombre.
class _TeamRow extends StatelessWidget {
  const _TeamRow({
    required this.name,
    required this.logo,
    required this.terms,
  });

  final String name;
  final String? logo;
  final List<String> terms;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _Crest(url: logo, name: name),
        const SizedBox(width: 10),
        Expanded(
          child: _Hl(
            name,
            terms,
            style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

/// Un casillero de la cuenta regresiva: número de dos cifras y su unidad.
class _CdCell extends StatelessWidget {
  const _CdCell({required this.value, required this.unit});

  final int value;
  final String unit;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              _p2(value),
              maxLines: 1,
              style: const TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.w700,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ),
          const SizedBox(height: 1),
          Text(
            unit,
            maxLines: 1,
            style: TextStyle(
              fontSize: 8.5,
              letterSpacing: 0.4,
              fontWeight: FontWeight.w600,
              color: _muted(context),
            ),
          ),
        ],
      ),
    );
  }
}

/// Columna derecha: cuenta regresiva (días, horas, minutos y segundos), «EN VIVO»
/// con ondas, o el estado del evento.
class _RightBlock extends StatelessWidget {
  const _RightBlock({required this.event, required this.now});

  final LiveEvent event;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final muted = TextStyle(fontSize: 11, color: _muted(context));
    if (event.liveNow) {
      return const Column(
        mainAxisAlignment: MainAxisAlignment.center,
        mainAxisSize: MainAxisSize.min,
        children: [
          _PulsingLive(),
          SizedBox(height: 3),
          Text(
            'EN VIVO',
            style: TextStyle(
              color: Pal.live,
              fontWeight: FontWeight.w800,
              fontSize: 12.5,
              letterSpacing: 0.6,
            ),
          ),
        ],
      );
    }
    final label = event.stateLabel;
    if (label != null) {
      return Center(
        child: Text(
          label,
          textAlign: TextAlign.center,
          maxLines: 2,
          style: TextStyle(
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
            color: _muted(context),
          ),
        ),
      );
    }
    final k = event.kickoff;
    if (k == null) {
      return Center(
        child: Text(event.status, textAlign: TextAlign.center, style: muted),
      );
    }
    if (event.timeTbc) {
      // Solo se conoce la fecha: días que faltan (por calendario) y aviso.
      final days = DateTime(k.year, k.month, k.day)
          .difference(DateTime(now.year, now.month, now.day))
          .inDays;
      final big = days <= 0 ? 'Hoy' : (days == 1 ? 'Mañana' : '$days');
      return Column(
        mainAxisAlignment: MainAxisAlignment.center,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            big,
            style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
          ),
          if (days > 1) Text('días', style: muted),
          const SizedBox(height: 2),
          Text('Hora a confirmar', style: muted, textAlign: TextAlign.center),
        ],
      );
    }
    final left = k.difference(now);
    if (left <= Duration.zero) {
      return Center(child: Text('Finalizado', style: muted));
    }
    // Redondea hacia arriba para que el último segundo sea 00:00:00:01.
    final secs = (left.inMilliseconds / 1000).ceil();
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('Empieza en', style: muted),
        const SizedBox(height: 4),
        Row(
          children: [
            _CdCell(value: secs ~/ 86400, unit: 'DÍAS'),
            _CdCell(value: (secs % 86400) ~/ 3600, unit: 'HRS'),
            _CdCell(value: (secs % 3600) ~/ 60, unit: 'MIN'),
            _CdCell(value: secs % 60, unit: 'SEG'),
          ],
        ),
      ],
    );
  }
}

/// Ícono de transmisión que late (ondas rojas), como en la referencia.
class _PulsingLive extends StatefulWidget {
  const _PulsingLive();

  @override
  State<_PulsingLive> createState() => _PulsingLiveState();
}

class _PulsingLiveState extends State<_PulsingLive>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (context, child) {
        final v = Curves.easeInOut.transform(_c.value);
        return Opacity(
          opacity: 0.45 + 0.55 * v,
          child: Transform.scale(scale: 0.9 + 0.2 * v, child: child),
        );
      },
      child: const Icon(Icons.sensors_rounded, size: 24, color: Pal.live),
    );
  }
}

/// Columna izquierda: hora (gris) y, debajo, el minuto en rojo si está en vivo
/// (o el día si todavía no empezó).
class _TimeColumn extends StatelessWidget {
  const _TimeColumn({required this.event, required this.now});

  final LiveEvent event;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final k = event.kickoff;
    final muted = _muted(context);
    final m = minuteLabel(event.minute);
    final live = event.liveNow;
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          k == null || event.timeTbc ? '--:--' : fmtHm(k),
          style: TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: muted,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        const SizedBox(height: 3),
        if (live && m != null)
          Text(
            m,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: Pal.live,
            ),
          )
        else if (!live && k != null)
          Text(
            _dayLabel(k),
            style: const TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: Pal.accent,
            ),
          ),
      ],
    );
  }
}

class _EventCard extends StatelessWidget {
  const _EventCard({
    required this.event,
    this.terms = const <String>[],
    this.onOpened,
  });

  final LiveEvent event;
  final List<String> terms;
  final VoidCallback? onOpened;

  @override
  Widget build(BuildContext context) {
    final border =
        Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.12);
    // Un solo reloj de 1 s reconstruye la tarjeta: cuenta regresiva, paso a
    // «en vivo» y borde rojo cambian sin recargar la lista.
    return ValueListenableBuilder<int>(
      valueListenable: _clock,
      builder: (context, tick, child) {
        final now = syncedNow();
        final live = event.liveNow;
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Material(
            color: _cardColor(context),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(
                color: live ? Pal.live.withValues(alpha: 0.55) : border,
              ),
            ),
            clipBehavior: Clip.antiAlias,
            // Toda la tarjeta abre el reproductor.
            child: InkWell(
              onTap: () {
                onOpened?.call();
                openEvent(context, event);
              },
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(14, 4, 4, 0),
                    child: Row(
                      children: [
                        Expanded(
                          child: _Hl(
                            event.league,
                            terms,
                            style: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                        if (event.playable)
                          const Padding(
                            padding: EdgeInsets.only(right: 2),
                            child: Icon(
                              Icons.play_circle_fill_rounded,
                              size: 18,
                              color: Pal.accent,
                            ),
                          ),
                        _FavButton(id: event.id),
                      ],
                    ),
                  ),
                  Divider(height: 1, thickness: 1, color: border),
                  IntrinsicHeight(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SizedBox(
                          width: 58,
                          child: _TimeColumn(event: event, now: now),
                        ),
                        Padding(
                          padding: const EdgeInsets.only(right: 10),
                          child: Center(
                            child: Icon(
                              sportIcon(event.sport, event.league),
                              size: 22,
                              color: _muted(context),
                            ),
                          ),
                        ),
                        Expanded(
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            child: event.away.isEmpty
                                ? Align(
                                    alignment: Alignment.centerLeft,
                                    child: _Hl(
                                      event.home,
                                      terms,
                                      maxLines: 3,
                                      style: const TextStyle(
                                        fontSize: 14.5,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  )
                                : Column(
                                    mainAxisAlignment:
                                        MainAxisAlignment.center,
                                    children: [
                                      _TeamRow(
                                        name: event.home,
                                        logo: event.homeLogo,
                                        terms: terms,
                                      ),
                                      const SizedBox(height: 8),
                                      _TeamRow(
                                        name: event.away,
                                        logo: event.awayLogo,
                                        terms: terms,
                                      ),
                                    ],
                                  ),
                          ),
                        ),
                        VerticalDivider(
                          width: 1,
                          thickness: 1,
                          indent: 10,
                          endIndent: 10,
                          color: border,
                        ),
                        SizedBox(
                          width: 132,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 8,
                            ),
                            child: _RightBlock(event: event, now: now),
                          ),
                        ),
                      ],
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
}

class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 24),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(color: _muted(context)),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 14, 2, 8),
      child: Text(
        text.toUpperCase(),
        style: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.9,
          color: _muted(context),
        ),
      ),
    );
  }
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.error, required this.onRetry});

  final String error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.cloud_off_rounded, size: 44, color: _muted(context)),
            const SizedBox(height: 12),
            const Text(
              'No pudimos conectar con Liveo',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            const Text(
              'Revisa que Apache y MySQL estén activos, que el teléfono esté '
              'en la misma red Wi-Fi y que el firewall permita el puerto 80.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              '$apiBase\n$error',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('Reintentar'),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Chips con contador
// ---------------------------------------------------------------------------

class _QuickChip extends StatelessWidget {
  const _QuickChip({
    required this.label,
    required this.count,
    required this.icon,
    required this.selected,
    required this.onTap,
    this.color,
  });

  final String label;
  final int count;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final c = color ?? Pal.accent;
    final border = cs.onSurface.withValues(alpha: 0.18);
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: Material(
        color: selected ? c.withValues(alpha: 0.14) : Colors.transparent,
        shape: StadiumBorder(
          side: BorderSide(
            color: selected ? c : border,
            width: selected ? 1.4 : 1,
          ),
        ),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  selected ? Icons.check_rounded : icon,
                  size: 17,
                  color: selected || color != null ? c : _muted(context),
                ),
                const SizedBox(width: 6),
                Text(
                  '$label ($count)',
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: selected ? c : cs.onSurface,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Inicio (pestaña Eventos)
// ---------------------------------------------------------------------------

class EventsHome extends StatefulWidget {
  const EventsHome({super.key, required this.refresh});

  final ValueListenable<int> refresh;

  @override
  State<EventsHome> createState() => _EventsHomeState();
}

class _EventsHomeState extends State<EventsHome> {
  bool _loading = true;
  String? _error;
  List<LiveEvent> _events = const <LiveEvent>[];
  _Quick _quick = _Quick.all;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_onRefresh);
    _load(initial: true);
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_onRefresh);
    super.dispose();
  }

  void _onRefresh() {
    _load();
  }

  Future<void> _load({bool initial = false}) async {
    if (!initial) {
      setState(() {
        _loading = _events.isEmpty;
        _error = null;
      });
    }
    try {
      final list = await EventsStore.instance.load();
      if (!mounted) return;
      setState(() {
        _events = list;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      if (_events.isNotEmpty) {
        setState(() => _loading = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se pudo actualizar la lista.')),
        );
        return;
      }
      setState(() {
        _error = '$e';
        _loading = false;
      });
    }
  }

  String _emptyText() {
    switch (_quick) {
      case _Quick.all:
        return 'Aún no hay eventos cargados.';
      case _Quick.live:
        return 'No hay partidos en vivo ahora.';
      case _Quick.today:
        return 'No hay más eventos para hoy.';
      case _Quick.tomorrow:
        return 'Todavía no hay eventos para mañana.';
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return _ErrorView(error: _error!, onRetry: _load);
    }
    final base = _homeEvents(_events);
    final shown = _applyQuick(base, _quick);
    int count(_Quick q) => _applyQuick(base, q).length;
    return Column(
      children: [
        SizedBox(
          height: 54,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
            children: [
              _QuickChip(
                label: 'Todos',
                count: count(_Quick.all),
                icon: Icons.apps_rounded,
                selected: _quick == _Quick.all,
                onTap: () => setState(() => _quick = _Quick.all),
              ),
              _QuickChip(
                label: 'En vivo',
                count: count(_Quick.live),
                icon: Icons.sensors_rounded,
                color: Pal.live,
                selected: _quick == _Quick.live,
                onTap: () => setState(() => _quick = _Quick.live),
              ),
              _QuickChip(
                label: 'Hoy',
                count: count(_Quick.today),
                icon: Icons.today_rounded,
                selected: _quick == _Quick.today,
                onTap: () => setState(() => _quick = _Quick.today),
              ),
              _QuickChip(
                label: 'Mañana',
                count: count(_Quick.tomorrow),
                icon: Icons.schedule_rounded,
                selected: _quick == _Quick.tomorrow,
                onTap: () => setState(() => _quick = _Quick.tomorrow),
              ),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: shown.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(12, 40, 12, 110),
                    children: [_Note(_emptyText())],
                  )
                : ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(12, 4, 12, 110),
                    itemCount: shown.length,
                    itemBuilder: (context, i) => _EventCard(event: shown[i]),
                  ),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Agenda (por día)
// ---------------------------------------------------------------------------

class AgendaTab extends StatefulWidget {
  const AgendaTab({super.key, required this.refresh});

  final ValueListenable<int> refresh;

  @override
  State<AgendaTab> createState() => _AgendaTabState();
}

class _AgendaTabState extends State<AgendaTab> {
  DateTime _date = DateTime.now();
  bool _loading = true;
  String? _error;
  List<LiveEvent> _events = const <LiveEvent>[];
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_onRefresh);
    _load(initial: true);
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_onRefresh);
    super.dispose();
  }

  void _onRefresh() {
    _load();
  }

  Future<void> _load({bool initial = false}) async {
    final my = ++_seq;
    if (!initial) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final list = await LiveoApi.events('schedule?date=${fmtYmd(_date)}');
      if (!mounted || my != _seq) return;
      setState(() {
        _events = [...list]..sort(_byKickoff);
        _loading = false;
      });
    } catch (e) {
      if (!mounted || my != _seq) return;
      setState(() {
        _error = '$e';
        _loading = false;
      });
    }
  }

  void _setDate(DateTime d) {
    setState(() => _date = d);
    _load();
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked != null && mounted) _setDate(picked);
  }

  Widget _dateBar() {
    final isToday = _sameDay(_date, DateTime.now());
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Día anterior',
            onPressed: () => _setDate(_date.subtract(const Duration(days: 1))),
            icon: const Icon(Icons.chevron_left_rounded),
          ),
          Expanded(
            child: Center(
              child: ActionChip(
                avatar: const Icon(Icons.calendar_today_outlined, size: 16),
                label: Text(
                  _dayText(_date),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                shape: const StadiumBorder(),
                onPressed: _pickDate,
              ),
            ),
          ),
          if (!isToday)
            TextButton(
              onPressed: () => _setDate(DateTime.now()),
              child: const Text('Hoy'),
            ),
          IconButton(
            tooltip: 'Día siguiente',
            onPressed: () => _setDate(_date.add(const Duration(days: 1))),
            icon: const Icon(Icons.chevron_right_rounded),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (_loading) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_error != null) {
      body = _ErrorView(error: _error!, onRetry: _load);
    } else {
      body = RefreshIndicator(
        onRefresh: _load,
        child: _events.isEmpty
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(12, 40, 12, 110),
                children: const [_Note('No hay eventos para este día.')],
              )
            : ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(12, 4, 12, 110),
                itemCount: _events.length,
                itemBuilder: (context, i) => _EventCard(event: _events[i]),
              ),
      );
    }
    return Column(
      children: [
        _dateBar(),
        Expanded(child: body),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Barra inferior flotante (píldora)
// ---------------------------------------------------------------------------

class _FloatingNav extends StatelessWidget {
  const _FloatingNav({required this.index, required this.onChanged});

  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    const icons = [
      Icons.sensors_outlined,
      Icons.calendar_month_outlined,
      Icons.tune_outlined,
    ];
    const selectedIcons = [
      Icons.sensors,
      Icons.calendar_month,
      Icons.tune,
    ];
    const labels = ['Eventos', 'Agenda', 'Ajustes'];
    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      child: Container(
        height: 68,
        padding: const EdgeInsets.all(6),
        decoration: BoxDecoration(
          color: _cardColor(context),
          borderRadius: BorderRadius.circular(34),
          border: Border.all(color: cs.onSurface.withValues(alpha: 0.12)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.35),
              blurRadius: 18,
              offset: const Offset(0, 6),
            ),
          ],
        ),
        child: Row(
          children: [
            for (var i = 0; i < labels.length; i++)
              Expanded(
                child: _NavItem(
                  icon: index == i ? selectedIcons[i] : icons[i],
                  label: labels[i],
                  selected: index == i,
                  onTap: () => onChanged(i),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = selected ? Pal.accent : _muted(context);
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        curve: Curves.easeOut,
        decoration: BoxDecoration(
          color: selected ? Pal.accent.withValues(alpha: 0.14) : Colors.transparent,
          borderRadius: BorderRadius.circular(28),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 24, color: c),
            const SizedBox(height: 3),
            Text(
              label,
              maxLines: 1,
              style: TextStyle(
                fontSize: 11.5,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color: c,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Favoritos
// ---------------------------------------------------------------------------

class FavoritesPage extends StatelessWidget {
  const FavoritesPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          'Favoritos',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      body: ValueListenableBuilder<List<String>>(
        valueListenable: FavStore.instance.ids,
        builder: (context, ids, child) {
          final list = EventsStore.instance.all
              .where((e) => ids.contains(e.id))
              .toList()
            ..sort(_liveFirst);
          if (list.isEmpty) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.star_border_rounded,
                      size: 48,
                      color: _muted(context),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      ids.isEmpty
                          ? 'Aún no tienes favoritos'
                          : 'Tus favoritos no están en hoy ni en mañana',
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      ids.isEmpty
                          ? 'Toca la estrella de un evento para guardarlo aquí.'
                          : 'Aparecerán aquí cuando vuelvan a estar en la agenda cargada.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: _muted(context)),
                    ),
                  ],
                ),
              ),
            );
          }
          return ListView.builder(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
            itemCount: list.length,
            itemBuilder: (context, i) => _EventCard(event: list[i]),
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Buscador
// ---------------------------------------------------------------------------

class _SuggestChip extends StatelessWidget {
  const _SuggestChip({
    required this.icon,
    required this.label,
    required this.onTap,
    this.color,
    this.selected = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color? color;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final c = color ?? Pal.accent;
    return Material(
      color: selected ? c.withValues(alpha: 0.16) : _cardColor(context),
      shape: StadiumBorder(
        side: BorderSide(
          color: selected ? c : cs.onSurface.withValues(alpha: 0.14),
        ),
      ),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 16, color: color ?? _muted(context)),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                    color: selected ? c : cs.onSurface,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _FadeIn extends StatelessWidget {
  const _FadeIn({super.key, required this.index, required this.child});

  final int index;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 0, end: 1),
      duration: Duration(milliseconds: 220 + math.min(index, 8) * 40),
      curve: Curves.easeOut,
      builder: (context, t, inner) => Opacity(
        opacity: t,
        child: Transform.translate(offset: Offset(0, (1 - t) * 12), child: inner),
      ),
      child: child,
    );
  }
}

class SearchPage extends StatefulWidget {
  const SearchPage({super.key});

  @override
  State<SearchPage> createState() => _SearchPageState();
}

class _SearchPageState extends State<SearchPage> {
  static const String _recentKey = 'recentSearches';

  final TextEditingController _ctrl = TextEditingController();
  final FocusNode _focus = FocusNode();
  List<LiveEvent> _events = EventsStore.instance.all;
  List<String> _recent = <String>[];
  bool _loading = false;
  bool _onlyLive = false;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _focus.addListener(_onFocus);
    _loadRecent();
    if (_events.isEmpty) _fetch();
  }

  @override
  void dispose() {
    _focus.removeListener(_onFocus);
    _focus.dispose();
    _ctrl.dispose();
    super.dispose();
  }

  void _onFocus() {
    if (mounted) setState(() {});
  }

  Future<void> _fetch() async {
    setState(() => _loading = true);
    try {
      final list = await EventsStore.instance.load();
      if (!mounted) return;
      setState(() {
        _events = list;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _loadRecent() async {
    final p = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _recent = List<String>.from(p.getStringList(_recentKey) ?? const <String>[]);
    });
  }

  Future<void> _storeRecent(List<String> list) async {
    setState(() => _recent = list);
    final p = await SharedPreferences.getInstance();
    await p.setStringList(_recentKey, list);
  }

  Future<void> _saveRecent(String raw) async {
    final t = raw.trim();
    if (t.length < 2) return;
    final list = <String>[
      t,
      ..._recent.where((r) => r.toLowerCase() != t.toLowerCase()),
    ];
    if (list.length > 8) list.removeRange(8, list.length);
    await _storeRecent(list);
  }

  Future<void> _removeRecent(String t) =>
      _storeRecent(_recent.where((r) => r != t).toList());

  Future<void> _clearRecent() => _storeRecent(<String>[]);

  void _setQuery(String v) {
    _ctrl.text = v;
    _ctrl.selection = TextSelection.collapsed(offset: v.length);
    setState(() => _q = v);
  }

  List<String> get _terms => _fold(_q)
      .split(RegExp(r'\s+'))
      .where((t) => t.isNotEmpty)
      .toList();

  List<LiveEvent> _results() {
    final terms = _terms;
    final out = _events.where((e) {
      if (_onlyLive && !e.liveNow) return false;
      if (terms.isEmpty) return true;
      final hay = _fold('${e.home} ${e.away} ${e.league}');
      return terms.every((t) => hay.contains(t));
    }).toList()
      ..sort(_liveFirst);
    return out;
  }

  Widget _searchBar(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final focused = _focus.hasFocus;
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 12, 4),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Volver',
            onPressed: () => Navigator.of(context).pop(),
            icon: const Icon(Icons.arrow_back_rounded),
          ),
          Expanded(
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 180),
              height: 48,
              decoration: BoxDecoration(
                color: _cardColor(context),
                borderRadius: BorderRadius.circular(24),
                border: Border.all(
                  color: focused
                      ? Pal.accent
                      : cs.onSurface.withValues(alpha: 0.12),
                  width: focused ? 1.6 : 1,
                ),
              ),
              child: Row(
                children: [
                  const SizedBox(width: 14),
                  Icon(
                    Icons.search_rounded,
                    color: focused ? Pal.accent : _muted(context),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextField(
                      controller: _ctrl,
                      focusNode: _focus,
                      autofocus: true,
                      textInputAction: TextInputAction.search,
                      onChanged: (v) => setState(() => _q = v),
                      onSubmitted: _saveRecent,
                      style: const TextStyle(fontSize: 16),
                      decoration: const InputDecoration(
                        isCollapsed: true,
                        filled: false,
                        border: InputBorder.none,
                        enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none,
                        hintText: 'Buscar equipo, liga o evento',
                      ),
                    ),
                  ),
                  if (_q.isNotEmpty)
                    IconButton(
                      tooltip: 'Borrar',
                      onPressed: () => _setQuery(''),
                      icon: const Icon(Icons.close_rounded, size: 20),
                    )
                  else
                    const SizedBox(width: 14),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _suggestions(BuildContext context) {
    final leagues = <String, int>{};
    for (final e in _events) {
      leagues[e.league] = (leagues[e.league] ?? 0) + 1;
    }
    final top = leagues.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    final liveCount = _events.where((e) => e.liveNow).length;
    return ListView(
      key: const ValueKey('suggestions'),
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        if (_loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          ),
        const _Label('Rápido'),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            _SuggestChip(
              icon: Icons.sensors_rounded,
              label: 'En vivo ahora ($liveCount)',
              color: Pal.live,
              onTap: () => setState(() => _onlyLive = true),
            ),
            for (final l in top.take(8))
              _SuggestChip(
                icon: Icons.emoji_events_outlined,
                label: l.key,
                onTap: () => _setQuery(l.key),
              ),
          ],
        ),
        if (_recent.isNotEmpty) ...[
          Row(
            children: [
              const Expanded(child: _Label('Recientes')),
              TextButton(
                onPressed: _clearRecent,
                child: const Text('Borrar todo'),
              ),
            ],
          ),
          for (final r in _recent)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: Icon(Icons.history_rounded, color: _muted(context)),
              title: Text(r),
              trailing: IconButton(
                tooltip: 'Quitar',
                icon: const Icon(Icons.close_rounded, size: 18),
                onPressed: () => _removeRecent(r),
              ),
              onTap: () => _setQuery(r),
            ),
        ],
        if (!_loading && _events.isEmpty)
          const _Note('No hay eventos cargados todavía. Revisa tu conexión.'),
      ],
    );
  }

  Widget _resultsView(BuildContext context) {
    final list = _results();
    final terms = _terms;
    return ListView(
      key: const ValueKey('results'),
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 24),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 4, 10),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  list.length == 1 ? '1 resultado' : '${list.length} resultados',
                  style: TextStyle(color: _muted(context)),
                ),
              ),
              _SuggestChip(
                icon: Icons.sensors_rounded,
                label: 'Solo en vivo',
                color: Pal.live,
                selected: _onlyLive,
                onTap: () => setState(() => _onlyLive = !_onlyLive),
              ),
            ],
          ),
        ),
        if (list.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 48),
            child: Column(
              children: [
                Icon(Icons.search_off_rounded, size: 48, color: _muted(context)),
                const SizedBox(height: 12),
                Text(
                  _q.trim().isEmpty
                      ? 'No hay partidos en vivo ahora'
                      : 'Sin resultados para “${_q.trim()}”',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Prueba con otro equipo o liga. Se busca en los eventos de hoy y mañana.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: _muted(context)),
                ),
              ],
            ),
          )
        else
          for (var i = 0; i < list.length; i++)
            _FadeIn(
              key: ValueKey(list[i].id.isEmpty ? 'i$i' : list[i].id),
              index: i,
              child: _EventCard(
                event: list[i],
                terms: terms,
                onOpened: () => _saveRecent(_q),
              ),
            ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final showResults = _q.trim().isNotEmpty || _onlyLive;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            _searchBar(context),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 180),
                child: showResults ? _resultsView(context) : _suggestions(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Ajustes
// ---------------------------------------------------------------------------

class _SettingsCard extends StatelessWidget {
  const _SettingsCard({
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
    this.color,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final border =
        Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.12);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: _cardColor(context),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: border),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
            child: Row(
              children: [
                Icon(icon, color: color ?? _muted(context)),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w600,
                          color: color,
                        ),
                      ),
                      if (subtitle != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            subtitle!,
                            style: TextStyle(
                              fontSize: 12.5,
                              color: _muted(context),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                if (trailing != null)
                  trailing!
                else if (onTap != null)
                  Icon(Icons.chevron_right_rounded, color: _muted(context)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key, required this.dark, required this.onTheme});

  final bool dark;
  final ValueChanged<bool> onTheme;

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  bool _pip = true;

  @override
  void initState() {
    super.initState();
    _loadPrefs();
  }

  Future<void> _loadPrefs() async {
    final p = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _pip = p.getBool('pipOnLeave') ?? true;
    });
  }

  Future<void> _setPip(bool v) async {
    setState(() => _pip = v);
    final p = await SharedPreferences.getInstance();
    await p.setBool('pipOnLeave', v);
  }

  Future<void> _clearData() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Borrar datos de la app'),
        content: const Text(
          'Se borrarán tus favoritos y el historial de búsquedas. '
          'El tema y los demás ajustes no cambian.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Borrar'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final p = await SharedPreferences.getInstance();
    await p.remove('favEvents');
    await p.remove('recentSearches');
    FavStore.instance.ids.value = <String>[];
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Datos borrados.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 110),
      children: [
        const _Label('General'),
        _SettingsCard(
          icon: widget.dark ? Icons.dark_mode_outlined : Icons.light_mode_outlined,
          title: 'Modo oscuro',
          trailing: Switch(value: widget.dark, onChanged: widget.onTheme),
          onTap: () => widget.onTheme(!widget.dark),
        ),
        _SettingsCard(
          icon: Icons.picture_in_picture_alt_outlined,
          title: 'Imagen en imagen al salir',
          subtitle: 'Al pulsar inicio con un directo abierto',
          trailing: Switch(value: _pip, onChanged: _setPip),
          onTap: () => _setPip(!_pip),
        ),
        const _Label('App'),
        _SettingsCard(
          icon: Icons.delete_outline_rounded,
          title: 'Borrar datos de la app',
          onTap: _clearData,
        ),
        const _SettingsCard(
          icon: Icons.info_outline_rounded,
          title: 'Liveo',
          subtitle: 'Versión $liveoVersion',
        ),
        _SettingsCard(
          icon: Icons.logout_rounded,
          title: 'Salir de la aplicación',
          color: Pal.live,
          onTap: () => SystemNavigator.pop(),
        ),
      ],
    );
  }
}
