import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:webview_flutter/webview_flutter.dart';

import 'cast_service.dart';
import 'live_player.dart';
import 'models.dart';
import 'native_bridge.dart';
import 'theme.dart';

export 'models.dart';

/// Versión visible de la app (mantener igual que pubspec.yaml).
const String liveoVersion = '1.0.5';

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

/// Abre el reproductor del evento: primero el propio (enlaces m3u8) y, si no
/// hay, el de Matchora dentro de la app.
void openEvent(BuildContext context, LiveEvent e) {
  final web = e.embedUrl;
  if (e.links.isNotEmpty) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => LivePlayerPage(
          event: e,
          openWeb: web == null
              ? null
              : (ctx) => Navigator.of(ctx).pushReplacement(
                    MaterialPageRoute<void>(
                      builder: (_) => WebPlayerPage(url: web, title: e.title),
                    ),
                  ),
        ),
      ),
    );
    return;
  }
  if (web != null) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => WebPlayerPage(url: web, title: e.title),
      ),
    );
    return;
  }
  ScaffoldMessenger.of(context).showSnackBar(
    const SnackBar(content: Text('Este evento todavía no tiene señal.')),
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Row(
          children: [
            LiveoLogo(size: 26),
            SizedBox(width: 10),
            Text(
              'LIVEO',
              style: TextStyle(fontWeight: FontWeight.w800, letterSpacing: 2.5),
            ),
          ],
        ),
      ),
      body: IndexedStack(
        index: tab,
        children: [
          const HomeTab(),
          const EventsTab(path: 'live', title: 'En vivo'),
          const EventsTab(path: 'schedule', title: 'Agenda', withDate: true),
          SettingsPage(dark: widget.dark, onTheme: widget.onTheme),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (i) => setState(() => tab = i),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Inicio',
          ),
          NavigationDestination(
            icon: Icon(Icons.sensors_outlined),
            selectedIcon: Icon(Icons.sensors),
            label: 'En vivo',
          ),
          NavigationDestination(
            icon: Icon(Icons.calendar_month_outlined),
            selectedIcon: Icon(Icons.calendar_month),
            label: 'Agenda',
          ),
          NavigationDestination(
            icon: Icon(Icons.tune_outlined),
            selectedIcon: Icon(Icons.tune),
            label: 'Ajustes',
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Listas de eventos (compactas, agrupadas por liga)
// ---------------------------------------------------------------------------

List<String>? _scoreParts(String? s) {
  if (s == null) return null;
  final p = s.split(RegExp(r'\s*[-:–]\s*'));
  if (p.length == 2 && p[0].isNotEmpty && p[1].isNotEmpty) return p;
  return null;
}

int _byKickoff(LiveEvent a, LiveEvent b) {
  final ta = a.kickoff;
  final tb = b.kickoff;
  if (ta == null && tb == null) return 0;
  if (ta == null) return 1;
  if (tb == null) return -1;
  return ta.compareTo(tb);
}

class _TimeCell extends StatelessWidget {
  const _TimeCell({required this.event});

  final LiveEvent event;

  @override
  Widget build(BuildContext context) {
    final k = event.kickoff;
    if (event.live) {
      return Text(
        minuteLabel(event.minute) ?? 'VIVO',
        style: const TextStyle(
          color: Pal.live,
          fontWeight: FontWeight.w700,
          fontSize: 13,
        ),
      );
    }
    if (k == null) {
      return Text('--:--', style: TextStyle(color: _muted(context)));
    }
    final now = DateTime.now();
    final today = k.year == now.year && k.month == now.month && k.day == now.day;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          fmtHm(k),
          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
        ),
        if (!today)
          Text(
            fmtDm(k),
            style: TextStyle(fontSize: 11, color: _muted(context)),
          ),
      ],
    );
  }
}

class _TeamLine extends StatelessWidget {
  const _TeamLine({required this.name, this.score});

  final String name;
  final String? score;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
          ),
        ),
        if (score != null) ...[
          const SizedBox(width: 8),
          Text(
            score!,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
          ),
        ],
      ],
    );
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.event});

  final LiveEvent event;

  @override
  Widget build(BuildContext context) {
    final parts = _scoreParts(event.score);
    return InkWell(
      onTap: () => openEvent(context, event),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            SizedBox(width: 50, child: _TimeCell(event: event)),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _TeamLine(name: event.home, score: parts?[0]),
                  const SizedBox(height: 5),
                  _TeamLine(name: event.away, score: parts?[1]),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(
              event.playable
                  ? Icons.play_circle_fill_rounded
                  : Icons.play_circle_outline_rounded,
              size: 28,
              color: event.playable
                  ? Pal.accent
                  : _muted(context).withValues(alpha: 0.45),
            ),
          ],
        ),
      ),
    );
  }
}

/// Eventos agrupados por liga: un título pequeño y una tarjeta por liga.
List<Widget> _groupedList(BuildContext context, List<LiveEvent> events) {
  final groups = <String, List<LiveEvent>>{};
  for (final e in events) {
    (groups[e.league] ??= <LiveEvent>[]).add(e);
  }
  final line = Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.07);
  final out = <Widget>[];
  groups.forEach((league, list) {
    out.add(
      Padding(
        padding: const EdgeInsets.fromLTRB(4, 14, 4, 6),
        child: Text(
          league.toUpperCase(),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.8,
            color: _muted(context),
          ),
        ),
      ),
    );
    out.add(
      Material(
        color: _cardColor(context),
        borderRadius: BorderRadius.circular(12),
        clipBehavior: Clip.antiAlias,
        child: Column(
          children: [
            for (var i = 0; i < list.length; i++) ...[
              if (i > 0)
                Divider(height: 1, thickness: 1, indent: 12, endIndent: 12, color: line),
              _EventTile(event: list[i]),
            ],
          ],
        ),
      ),
    );
  });
  return out;
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.title, this.count);

  final String title;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 12, 4, 0),
      child: Row(
        children: [
          Text(
            title,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
          ),
          const SizedBox(width: 8),
          Text('$count', style: TextStyle(color: _muted(context))),
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 16),
      child: Text(text, style: TextStyle(color: _muted(context))),
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
// Inicio
// ---------------------------------------------------------------------------

class _HomeData {
  const _HomeData(this.live, this.upcoming);

  final List<LiveEvent> live;
  final List<LiveEvent> upcoming;
}

class HomeTab extends StatefulWidget {
  const HomeTab({super.key});

  @override
  State<HomeTab> createState() => _HomeTabState();
}

class _HomeTabState extends State<HomeTab> {
  late Future<_HomeData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_HomeData> _load() async {
    Object? firstError;
    Future<List<LiveEvent>> safe(String path) async {
      try {
        return await LiveoApi.events(path);
      } catch (e) {
        firstError ??= e;
        return const <LiveEvent>[];
      }
    }

    final res = await Future.wait([safe('live'), safe('schedule')]);
    final live = res[0];
    final schedule = res[1];
    if (live.isEmpty && schedule.isEmpty && firstError != null) {
      throw firstError!;
    }
    final liveIds = live.map((e) => e.id).toSet();
    final from = DateTime.now().subtract(const Duration(minutes: 10));
    // Próximos: solo los que tienen señal (enlace propio o Matchora).
    final upcoming = schedule
        .where((e) => !e.live && !liveIds.contains(e.id) && e.playable)
        .where((e) => e.kickoff == null || e.kickoff!.isAfter(from))
        .toList()
      ..sort(_byKickoff);
    final liveSorted = [...live]..sort(_byKickoff);
    return _HomeData(liveSorted, upcoming.take(40).toList());
  }

  Future<void> _reload() async {
    final f = _load();
    setState(() => _future = f);
    try {
      await f;
    } catch (_) {
      // El error se muestra en el FutureBuilder.
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<_HomeData>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snap.hasError) {
          return _ErrorView(error: '${snap.error}', onRetry: _reload);
        }
        final data = snap.data ?? const _HomeData([], []);
        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
            children: [
              _SectionHeader('En vivo', data.live.length),
              if (data.live.isEmpty)
                const _Note('No hay partidos en vivo ahora.')
              else
                ..._groupedList(context, data.live),
              const SizedBox(height: 14),
              _SectionHeader('Próximos', data.upcoming.length),
              if (data.upcoming.isEmpty)
                const _Note(
                  'Aún no hay eventos con señal. Mira la Agenda para ver todos.',
                )
              else
                ..._groupedList(context, data.upcoming),
            ],
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// En vivo y Agenda (mismo formato de lista)
// ---------------------------------------------------------------------------

class EventsTab extends StatefulWidget {
  const EventsTab({
    super.key,
    required this.path,
    required this.title,
    this.withDate = false,
  });

  final String path;
  final String title;
  final bool withDate;

  @override
  State<EventsTab> createState() => _EventsTabState();
}

class _EventsTabState extends State<EventsTab> {
  late Future<List<LiveEvent>> _future;
  DateTime _date = DateTime.now();

  String get _path =>
      widget.withDate ? '${widget.path}?date=${fmtYmd(_date)}' : widget.path;

  @override
  void initState() {
    super.initState();
    _future = _fetch();
  }

  Future<List<LiveEvent>> _fetch() async {
    final list = await LiveoApi.events(_path);
    return [...list]..sort(_byKickoff);
  }

  Future<void> _reload() async {
    final f = _fetch();
    setState(() => _future = f);
    try {
      await f;
    } catch (_) {
      // El error se muestra en el FutureBuilder.
    }
  }

  void _setDate(DateTime d) {
    setState(() {
      _date = d;
      _future = _fetch();
    });
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
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 0),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Día anterior',
            onPressed: () => _setDate(_date.subtract(const Duration(days: 1))),
            icon: const Icon(Icons.chevron_left),
          ),
          Expanded(
            child: TextButton.icon(
              onPressed: _pickDate,
              icon: const Icon(Icons.calendar_today_outlined, size: 18),
              label: Text(fmtYmd(_date)),
            ),
          ),
          IconButton(
            tooltip: 'Día siguiente',
            onPressed: () => _setDate(_date.add(const Duration(days: 1))),
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (widget.withDate) _dateBar(),
        Expanded(
          child: FutureBuilder<List<LiveEvent>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snap.hasError) {
                return _ErrorView(error: '${snap.error}', onRetry: _reload);
              }
              final events = snap.data ?? const <LiveEvent>[];
              return RefreshIndicator(
                onRefresh: _reload,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
                  children: [
                    _SectionHeader(widget.title, events.length),
                    if (events.isEmpty)
                      const _Note('No hay eventos disponibles en este momento.')
                    else
                      ..._groupedList(context, events),
                  ],
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Reproductor de Matchora (respaldo): su embed dentro de un iframe, en horizontal
// ---------------------------------------------------------------------------

/// Página mínima que incrusta el reproductor en un iframe, como indica la
/// documentación de Matchora (https://matchora.to/developers). Incluye la
/// selección automática de dominio de respaldo para los países donde algún
/// dominio está bloqueado. No se oculta ni se tapa la publicidad del embed.
String _playerHtml(String embedUrl) {
  final u = Uri.parse(embedUrl);
  final path = u.hasQuery ? '${u.path}?${u.query}' : u.path;
  const mirrors = ['matchora.to', 'matchora.pro', 'es.matchora.pro'];
  final isMatchora = mirrors.contains(u.host);
  final origins = isMatchora
      ? <String>[
          'https://${u.host}',
          ...mirrors.where((h) => h != u.host).map((h) => 'https://$h'),
        ]
      : <String>[u.origin];
  final hostsJs = jsonEncode(origins).replaceAll('</', r'<\/');
  final pathJs = jsonEncode(path).replaceAll('</', r'<\/');
  return '''<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<style>
html,body{margin:0;height:100%;background:#000;overflow:hidden}
iframe{position:fixed;top:0;left:0;width:100%;height:100%;border:0;background:#000}
</style></head><body>
<script>
(function(){
var hosts=$hostsJs,path=$pathJs,ok=[],done=false,n=hosts.length-1;
function show(h){if(done)return;done=true;var f=document.createElement("iframe");
f.src=h+path;f.setAttribute("frameborder","0");f.allowFullscreen=true;
f.allow="autoplay; fullscreen; encrypted-media; picture-in-picture";document.body.appendChild(f);}
function pick(){for(var i=0;i<n;i++){if(ok[i])return show(hosts[i]);if(ok[i]!==false)return;}show(hosts[n]);}
function set(i,v){if(ok[i]===undefined){ok[i]=v;pick();}}
if(!n)return show(hosts[0]);
hosts.slice(0,n).forEach(function(h,i){
var t=setTimeout(function(){set(i,false);},4000);
fetch(h+"/front-health",{mode:"no-cors",cache:"no-store"}).then(
function(){clearTimeout(t);set(i,true);},
function(){clearTimeout(t);set(i,false);});});
})();
</script></body></html>''';
}

class WebPlayerPage extends StatefulWidget {
  const WebPlayerPage({super.key, required this.url, required this.title});

  final String url;
  final String title;

  @override
  State<WebPlayerPage> createState() => _WebPlayerPageState();
}

class _WebPlayerPageState extends State<WebPlayerPage> {
  late final WebViewController controller;
  late final String _base = '${Uri.parse(apiBase).origin}/';
  Timer? _hideTimer;
  bool _loading = true;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    ScreenMode.landscape();
    controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.black)
      ..setNavigationDelegate(
        NavigationDelegate(
          onNavigationRequest: _onNavigation,
          onPageFinished: (_) {
            _hideTimer?.cancel();
            _hideTimer = Timer(const Duration(milliseconds: 1200), () {
              if (mounted) setState(() => _loading = false);
            });
          },
          onWebResourceError: (e) {
            if (e.isForMainFrame == true && mounted) {
              setState(() {
                _failed = true;
                _loading = false;
              });
            }
          },
        ),
      );
    _loadPlayer();
  }

  NavigationDecision _onNavigation(NavigationRequest r) {
    if (!r.isMainFrame) {
      final u = Uri.tryParse(r.url);
      final ok = u != null && (u.scheme == 'http' || u.scheme == 'https');
      return ok ? NavigationDecision.navigate : NavigationDecision.prevent;
    }
    // Solo se permite la página contenedora; el reproductor no puede sacar
    // a la persona de la app.
    if (r.url == _base ||
        r.url.startsWith('about:') ||
        r.url.startsWith('data:')) {
      return NavigationDecision.navigate;
    }
    return NavigationDecision.prevent;
  }

  void _loadPlayer() {
    controller.loadHtmlString(_playerHtml(widget.url), baseUrl: _base);
  }

  void _reload() {
    setState(() {
      _loading = true;
      _failed = false;
    });
    _loadPlayer();
  }

  @override
  void dispose() {
    _hideTimer?.cancel();
    ScreenMode.restore();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          Positioned.fill(child: WebViewWidget(controller: controller)),
          if (_loading)
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
          if (_failed)
            Positioned.fill(
              child: Container(
                color: Colors.black,
                alignment: Alignment.center,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text(
                      'No se pudo cargar el reproductor',
                      style: TextStyle(color: Colors.white70),
                    ),
                    TextButton(
                      onPressed: _reload,
                      child: const Text('Reintentar'),
                    ),
                  ],
                ),
              ),
            ),
          Positioned(
            top: 4,
            left: 4,
            child: SafeArea(
              child: IconButton(
                tooltip: 'Volver',
                onPressed: () => Navigator.of(context).maybePop(),
                style: IconButton.styleFrom(backgroundColor: Colors.black38),
                icon: const Icon(Icons.arrow_back, color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Ajustes
// ---------------------------------------------------------------------------

class SettingsPage extends StatelessWidget {
  const SettingsPage({super.key, required this.dark, required this.onTheme});

  final bool dark;
  final ValueChanged<bool> onTheme;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(4, 0, 4, 24),
      children: [
        SwitchListTile(
          title: const Text('Modo oscuro'),
          value: dark,
          onChanged: onTheme,
          secondary: Icon(dark ? Icons.dark_mode : Icons.light_mode),
        ),
        const ListTile(
          leading: Icon(Icons.dns_outlined),
          title: Text('Servidor'),
          subtitle: Text(apiBase),
        ),
        const ListTile(
          leading: Icon(Icons.play_circle_outline_rounded),
          title: Text('Reproductor'),
          subtitle: Text(
            'Propio (m3u8 en vivo) con imagen en imagen y Chromecast. '
            'Matchora queda como respaldo.',
          ),
        ),
        const ListTile(
          leading: LiveoLogo(size: 28),
          title: Text('Liveo'),
          subtitle: Text('Versión $liveoVersion'),
        ),
      ],
    );
  }
}
