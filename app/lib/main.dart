import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:webview_flutter/webview_flutter.dart';

/// URL base de la API de Liveo. Se define al compilar con
/// --dart-define=API_BASE_URL=http://IP/Liveo/api
const String apiBase = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://192.168.54.109/Liveo/api',
);

/// Versión visible de la app (mantener igual que pubspec.yaml).
const String liveoVersion = '1.0.2';

void main() => runApp(const LiveoApp());

// ---------------------------------------------------------------------------
// Paleta
// ---------------------------------------------------------------------------

class Pal {
  static const lime = Color(0xffb6ff5c);
  static const cyan = Color(0xff22d3ee);
  static const violet = Color(0xff8b5cf6);
  static const pink = Color(0xfff43f5e);
  static const orange = Color(0xfffb923c);
  static const ink = Color(0xff07210f);

  static const brand = LinearGradient(colors: [lime, cyan]);
  static const hot = LinearGradient(colors: [pink, orange]);
}

// ---------------------------------------------------------------------------
// Utilidades y modelo
// ---------------------------------------------------------------------------

String _two(int n) => n.toString().padLeft(2, '0');

String _ymd(DateTime d) => '${d.year}-${_two(d.month)}-${_two(d.day)}';

String _fmtTime(DateTime t) =>
    '${_two(t.day)}/${_two(t.month)} · ${_two(t.hour)}:${_two(t.minute)}';

String _hm(DateTime t) => '${_two(t.hour)}:${_two(t.minute)}';

String _name(Object? v, String fallback) {
  if (v == null) return fallback;
  if (v is Map) {
    final n = v['name'] ?? v['title'] ?? v['short_name'];
    if (n != null && '$n'.trim().isNotEmpty) return '$n'.trim();
    return fallback;
  }
  final s = '$v'.trim();
  return s.isEmpty ? fallback : s;
}

DateTime? _time(Object? v) {
  if (v == null) return null;
  if (v is num) {
    var secs = v.toInt();
    if (secs <= 0) return null;
    if (secs > 100000000000) secs = secs ~/ 1000;
    return DateTime.fromMillisecondsSinceEpoch(secs * 1000, isUtc: true)
        .toLocal();
  }
  final s = '$v'.trim();
  final n = int.tryParse(s);
  if (n != null) return _time(n);
  return DateTime.tryParse(s)?.toLocal();
}

bool _truthy(Object? v) =>
    v == true || '$v'.toLowerCase() == 'true' || '$v' == '1';

String? _minuteLabel(String? m) {
  if (m == null || m.trim().isEmpty) return null;
  final t = m.trim();
  return int.tryParse(t) != null ? "$t'" : t;
}

Color _hueColor(String s, {double l = 0.5}) {
  var h = 0;
  for (final r in s.runes) {
    h = (h * 31 + r) & 0x7fffffff;
  }
  return HSLColor.fromAHSL(1.0, (h % 360).toDouble(), 0.65, l).toColor();
}

String _initials(String s) {
  final parts =
      s.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  if (parts.length == 1) {
    return String.fromCharCodes(parts[0].runes.take(3)).toUpperCase();
  }
  return (String.fromCharCode(parts[0].runes.first) +
          String.fromCharCode(parts[1].runes.first))
      .toUpperCase();
}

class LiveEvent {
  const LiveEvent({
    required this.id,
    required this.league,
    required this.home,
    required this.away,
    required this.score,
    required this.live,
    required this.kickoff,
    required this.status,
    required this.embedUrl,
    this.minute,
  });

  final String id;
  final String league;
  final String home;
  final String away;
  final String? score;
  final bool live;
  final DateTime? kickoff;
  final String status;
  final String? embedUrl;
  final String? minute;

  factory LiveEvent.fromJson(Map<String, dynamic> j) {
    String? embed;
    final rawEmbed = j['embed_url'];
    if (rawEmbed is String && rawEmbed.isNotEmpty) {
      final uri = Uri.tryParse(rawEmbed);
      if (uri != null && (uri.scheme == 'http' || uri.scheme == 'https')) {
        embed = rawEmbed;
      }
    }

    String? score;
    final rawScore = j['score'];
    if (rawScore is String && rawScore.trim().isNotEmpty) {
      score = rawScore.trim();
    } else if (rawScore is num) {
      score = '$rawScore';
    }

    String? minute;
    final rawMinute = j['minute'];
    if ((rawMinute is String && rawMinute.trim().isNotEmpty) ||
        rawMinute is num) {
      minute = '$rawMinute'.trim();
    }

    return LiveEvent(
      id: '${j['id'] ?? ''}',
      league: _name(j['league'], 'Deporte'),
      home: _name(j['home'], 'Local'),
      away: _name(j['away'], 'Visitante'),
      score: score,
      live: _truthy(j['live']),
      kickoff: _time(j['kickoff']),
      status: _name(j['status'], 'Horario a confirmar'),
      embedUrl: embed,
      minute: minute,
    );
  }
}

class LiveoApi {
  static Future<List<LiveEvent>> events(String path) async {
    final uri = Uri.parse('$apiBase/$path');
    final r = await http
        .get(uri, headers: {'Accept': 'application/json'})
        .timeout(const Duration(seconds: 25));
    if (r.statusCode != 200) {
      throw Exception('HTTP ${r.statusCode}');
    }
    final data = jsonDecode(utf8.decode(r.bodyBytes));
    if (data is! Map) {
      throw const FormatException('Respuesta inesperada del servidor');
    }
    final list = data['events'];
    if (list is! List) return const <LiveEvent>[];
    return list
        .whereType<Map>()
        .map((e) => LiveEvent.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }
}

// ---------------------------------------------------------------------------
// Componentes visuales reutilizables
// ---------------------------------------------------------------------------

bool _isDark(BuildContext context) =>
    Theme.of(context).brightness == Brightness.dark;

BoxDecoration _glass(BuildContext context, {Color? glow, double radius = 22}) {
  final dark = _isDark(context);
  return BoxDecoration(
    borderRadius: BorderRadius.circular(radius),
    color: dark
        ? Colors.white.withValues(alpha: 0.06)
        : Colors.white.withValues(alpha: 0.85),
    border: Border.all(
      color: glow != null
          ? glow.withValues(alpha: 0.55)
          : (dark
              ? Colors.white.withValues(alpha: 0.10)
              : Colors.black.withValues(alpha: 0.06)),
    ),
    boxShadow: [
      BoxShadow(
        color: (glow ?? Colors.black).withValues(alpha: glow != null ? 0.18 : 0.08),
        blurRadius: 18,
        offset: const Offset(0, 8),
      ),
    ],
  );
}

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

/// Escala suave al pulsar.
class _Pressable extends StatefulWidget {
  const _Pressable({required this.child, this.onTap});

  final Widget child;
  final VoidCallback? onTap;

  @override
  State<_Pressable> createState() => _PressableState();
}

class _PressableState extends State<_Pressable> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => _set(true),
      onTapUp: (_) => _set(false),
      onTapCancel: () => _set(false),
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _down ? 0.97 : 1,
        duration: const Duration(milliseconds: 120),
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}

/// Entrada con desvanecido y deslizamiento, con retardo opcional.
class _FadeSlide extends StatefulWidget {
  const _FadeSlide({super.key, required this.child, this.delay = Duration.zero});

  final Widget child;
  final Duration delay;

  @override
  State<_FadeSlide> createState() => _FadeSlideState();
}

class _FadeSlideState extends State<_FadeSlide>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 480),
  );
  late final Animation<double> _fade =
      CurvedAnimation(parent: _c, curve: Curves.easeOut);
  late final Animation<Offset> _slide = Tween<Offset>(
    begin: const Offset(0, 0.12),
    end: Offset.zero,
  ).animate(CurvedAnimation(parent: _c, curve: Curves.easeOutCubic));

  @override
  void initState() {
    super.initState();
    Future<void>.delayed(widget.delay, () {
      if (mounted) _c.forward();
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: SlideTransition(position: _slide, child: widget.child),
    );
  }
}

/// Opacidad que late (para esqueletos de carga).
class _Pulse extends StatefulWidget {
  const _Pulse({required this.child});

  final Widget child;

  @override
  State<_Pulse> createState() => _PulseState();
}

class _PulseState extends State<_Pulse> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: 0.35, end: 0.95).animate(_c),
      child: widget.child,
    );
  }
}

class _SkeletonBox extends StatelessWidget {
  const _SkeletonBox({required this.height, this.radius = 22});

  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final dark = _isDark(context);
    return _Pulse(
      child: Container(
        height: height,
        margin: const EdgeInsets.only(bottom: 14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(radius),
          color: dark
              ? Colors.white.withValues(alpha: 0.08)
              : Colors.black.withValues(alpha: 0.07),
        ),
      ),
    );
  }
}

class _Skeleton extends StatelessWidget {
  const _Skeleton({this.hero = true});

  final bool hero;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 0),
      children: [
        const _SkeletonBox(height: 40, radius: 14),
        if (hero) const _SkeletonBox(height: 224, radius: 26),
        const _SkeletonBox(height: 150),
        const _SkeletonBox(height: 150),
        const _SkeletonBox(height: 150),
      ],
    );
  }
}

class LivePill extends StatefulWidget {
  const LivePill({super.key, this.minute, this.label});

  final String? minute;
  final String? label;

  @override
  State<LivePill> createState() => _LivePillState();
}

class _LivePillState extends State<LivePill>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final text = widget.label ??
        (widget.minute != null ? 'EN VIVO · ${widget.minute}' : 'EN VIVO');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        gradient: Pal.hot,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Pal.pink.withValues(alpha: 0.5),
            blurRadius: 12,
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          FadeTransition(
            opacity: Tween<double>(begin: 0.25, end: 1).animate(_c),
            child: Container(
              width: 7,
              height: 7,
              decoration: const BoxDecoration(
                color: Colors.white,
                shape: BoxShape.circle,
              ),
            ),
          ),
          const SizedBox(width: 6),
          Text(
            text,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.8,
            ),
          ),
        ],
      ),
    );
  }
}

class _TimeTag extends StatelessWidget {
  const _TimeTag(this.time, {this.onDark = false});

  final DateTime time;
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: onDark
            ? Colors.white.withValues(alpha: 0.18)
            : Pal.cyan.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        _hm(time),
        style: TextStyle(
          color: onDark ? Colors.white : Theme.of(context).colorScheme.onSurface,
          fontSize: 12,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class TeamBadge extends StatelessWidget {
  const TeamBadge({super.key, required this.name, this.size = 52});

  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = _hueColor(name);
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [c, _hueColor('$name#', l: 0.32)],
        ),
        border: Border.all(color: Colors.white.withValues(alpha: 0.35), width: 1.5),
        boxShadow: [
          BoxShadow(
            color: c.withValues(alpha: 0.45),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Text(
        _initials(name),
        style: TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w900,
          fontSize: size * 0.32,
        ),
      ),
    );
  }
}

class _TeamCol extends StatelessWidget {
  const _TeamCol({required this.name, this.color = Colors.white});

  final String name;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        TeamBadge(name: name),
        const SizedBox(height: 8),
        Text(
          name,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: color,
            fontWeight: FontWeight.w700,
            fontSize: 13,
            height: 1.15,
          ),
        ),
      ],
    );
  }
}

class _ScoreBox extends StatelessWidget {
  const _ScoreBox({this.score, this.color = Colors.white});

  final String? score;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10),
      child: score != null
          ? Text(
              score!,
              style: TextStyle(
                color: color,
                fontSize: 26,
                fontWeight: FontWeight.w900,
                letterSpacing: 1,
              ),
            )
          : Text(
              'VS',
              style: TextStyle(
                color: color.withValues(alpha: 0.6),
                fontSize: 16,
                fontWeight: FontWeight.w800,
                letterSpacing: 2,
              ),
            ),
    );
  }
}

class _WatchButton extends StatelessWidget {
  const _WatchButton({this.label = 'Ver ahora'});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 40,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        gradient: Pal.brand,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Pal.lime.withValues(alpha: 0.35),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.play_arrow_rounded, color: Pal.ink, size: 22),
          const SizedBox(width: 4),
          Text(
            label,
            style: const TextStyle(
              color: Pal.ink,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text, {this.trailing});

  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 4,
          height: 18,
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [Pal.lime, Pal.cyan],
            ),
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            text,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w900,
                  letterSpacing: 1.4,
                ),
          ),
        ),
        if (trailing != null) trailing!,
      ],
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 48),
      child: Column(
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                colors: [
                  Pal.violet.withValues(alpha: 0.5),
                  Pal.cyan.withValues(alpha: 0.5),
                ],
              ),
            ),
            child: Icon(icon, size: 34, color: Colors.white),
          ),
          const SizedBox(height: 14),
          Text(text, textAlign: TextAlign.center),
        ],
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
            Container(
              width: 84,
              height: 84,
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                gradient: Pal.hot,
              ),
              child: const Icon(
                Icons.cloud_off_rounded,
                size: 40,
                color: Colors.white,
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'No pudimos conectar con Liveo',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'Revisa que Apache y MySQL estén activos, que el teléfono esté '
              'en la misma red Wi-Fi y que el firewall permita el puerto 80.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              apiBase,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 4),
            Text(
              error,
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

void openPlayer(BuildContext context, LiveEvent e) {
  final url = e.embedUrl;
  if (url == null) return;
  Navigator.of(context).push(
    PageRouteBuilder<void>(
      transitionDuration: const Duration(milliseconds: 350),
      pageBuilder: (context, anim, secondary) => PlayerPage(
        url: url,
        title: '${e.home} vs ${e.away}',
        live: e.live,
        minute: _minuteLabel(e.minute),
      ),
      transitionsBuilder: (context, anim, secondary, child) {
        return FadeTransition(
          opacity: anim,
          child: SlideTransition(
            position: Tween<Offset>(
              begin: const Offset(0, 0.06),
              end: Offset.zero,
            ).animate(CurvedAnimation(parent: anim, curve: Curves.easeOutCubic)),
            child: child,
          ),
        );
      },
    ),
  );
}

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------

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
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xff86d957),
          brightness: Brightness.light,
        ),
        scaffoldBackgroundColor: const Color(0xfff4f7ff),
        useMaterial3: true,
      ),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xffa4e66a),
          brightness: Brightness.dark,
          surface: const Color(0xff101a2a),
        ),
        scaffoldBackgroundColor: const Color(0xff070b14),
        useMaterial3: true,
      ),
      home: SplashGate(
        child: HomePage(dark: mode == ThemeMode.dark, onTheme: _toggleTheme),
      ),
    );
  }
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
    return AuroraBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        extendBody: true,
        body: SafeArea(
          bottom: false,
          child: IndexedStack(
            index: tab,
            children: [
              HomeTab(
                onGoLive: () => setState(() => tab = 1),
              ),
              const EventsTab(path: 'live', title: 'En directo'),
              const EventsTab(path: 'schedule', title: 'Agenda', withDate: true),
              SettingsPage(dark: widget.dark, onTheme: widget.onTheme),
            ],
          ),
        ),
        bottomNavigationBar: _FloatingNav(
          index: tab,
          onChanged: (v) => setState(() => tab = v),
        ),
      ),
    );
  }
}

class _FloatingNav extends StatelessWidget {
  const _FloatingNav({required this.index, required this.onChanged});

  final int index;
  final ValueChanged<int> onChanged;

  static const _items = <(IconData, String)>[
    (Icons.home_rounded, 'Inicio'),
    (Icons.sensors_rounded, 'En vivo'),
    (Icons.calendar_month_rounded, 'Agenda'),
    (Icons.tune_rounded, 'Ajustes'),
  ];

  @override
  Widget build(BuildContext context) {
    final dark = _isDark(context);
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(30),
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
            child: Container(
              height: 66,
              decoration: BoxDecoration(
                color: dark
                    ? const Color(0xff111a33).withValues(alpha: 0.72)
                    : Colors.white.withValues(alpha: 0.82),
                borderRadius: BorderRadius.circular(30),
                border: Border.all(
                  color: dark
                      ? Colors.white.withValues(alpha: 0.12)
                      : Colors.black.withValues(alpha: 0.06),
                ),
              ),
              child: LayoutBuilder(
                builder: (context, c) {
                  const selected = 3.0;
                  final unit = c.maxWidth / (_items.length - 1 + selected);
                  return Row(
                    children: [
                      for (var i = 0; i < _items.length; i++)
                        GestureDetector(
                          behavior: HitTestBehavior.opaque,
                          onTap: () => onChanged(i),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 320),
                            curve: Curves.easeOutCubic,
                            width: unit * (i == index ? selected : 1),
                            height: 66,
                            padding: const EdgeInsets.symmetric(
                              vertical: 10,
                              horizontal: 4,
                            ),
                            child: AnimatedContainer(
                              duration: const Duration(milliseconds: 320),
                              curve: Curves.easeOutCubic,
                              decoration: BoxDecoration(
                                gradient: i == index ? Pal.brand : null,
                                borderRadius: BorderRadius.circular(22),
                              ),
                              child: FittedBox(
                                fit: BoxFit.scaleDown,
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(
                                      _items[i].$1,
                                      size: 24,
                                      color: i == index
                                          ? Pal.ink
                                          : (dark
                                              ? Colors.white70
                                              : Colors.black54),
                                    ),
                                    if (i == index) ...[
                                      const SizedBox(width: 6),
                                      Text(
                                        _items[i].$2,
                                        style: const TextStyle(
                                          color: Pal.ink,
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  );
                },
              ),
            ),
          ),
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
  const HomeTab({super.key, required this.onGoLive});

  final VoidCallback onGoLive;

  @override
  State<HomeTab> createState() => _HomeTabState();
}

class _HomeTabState extends State<HomeTab> {
  late Future<_HomeData> _future;
  final PageController _pager = PageController(viewportFraction: 0.9);
  int _page = 0;
  String? _league;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _pager.dispose();
    super.dispose();
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

    final res = await Future.wait([
      safe('live'),
      safe('schedule?watchable=1'),
    ]);
    final live = res[0];
    var upcoming = res[1];
    if (live.isEmpty && upcoming.isEmpty && firstError != null) {
      throw firstError!;
    }
    final liveIds = live.map((e) => e.id).toSet();
    upcoming = upcoming.where((e) => !liveIds.contains(e.id)).toList();
    upcoming.sort((a, b) {
      final ta = a.kickoff;
      final tb = b.kickoff;
      if (ta == null && tb == null) return 0;
      if (ta == null) return 1;
      if (tb == null) return -1;
      return ta.compareTo(tb);
    });
    return _HomeData(live, upcoming);
  }

  Future<void> _reload() async {
    final f = _load();
    setState(() {
      _future = f;
      _page = 0;
      _league = null;
    });
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
          return const _Skeleton();
        }
        if (snap.hasError) {
          return _ErrorView(error: '${snap.error}', onRetry: _reload);
        }
        final data = snap.data ?? const _HomeData([], []);
        final featured =
            data.live.isNotEmpty ? data.live : data.upcoming.take(5).toList();
        final leagues = <String>[];
        for (final e in data.upcoming) {
          if (!leagues.contains(e.league)) leagues.add(e.league);
        }
        final chips = leagues.take(12).toList();
        final filtered = _league == null
            ? data.upcoming
            : data.upcoming.where((e) => e.league == _league).toList();
        final shown = filtered.take(40).toList();
        final scheme = Theme.of(context).colorScheme;

        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 110),
            children: [
              _FadeSlide(
                child: Row(
                  children: [
                    const LogoTile(size: 48),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const GradientText(
                            'LIVEO',
                            style: TextStyle(
                              fontSize: 30,
                              fontWeight: FontWeight.w900,
                              letterSpacing: 4,
                              height: 1.1,
                            ),
                          ),
                          Text(
                            'Todo el deporte en directo',
                            style: TextStyle(
                              color: scheme.onSurface.withValues(alpha: 0.65),
                              fontSize: 13,
                              letterSpacing: 0.3,
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (data.live.isNotEmpty)
                      LivePill(label: '${data.live.length} EN VIVO'),
                  ],
                ),
              ),
              const SizedBox(height: 22),
              _FadeSlide(
                delay: const Duration(milliseconds: 80),
                child: _SectionTitle(
                  data.live.isNotEmpty ? 'EN DIRECTO AHORA' : 'DESTACADOS',
                  trailing: data.live.isNotEmpty
                      ? TextButton(
                          onPressed: widget.onGoLive,
                          child: const Text('Ver todo'),
                        )
                      : null,
                ),
              ),
              const SizedBox(height: 12),
              if (featured.isEmpty)
                const _EmptyState(
                  icon: Icons.sports_soccer_rounded,
                  text: 'No hay eventos disponibles en este momento.',
                )
              else ...[
                _FadeSlide(
                  delay: const Duration(milliseconds: 140),
                  child: SizedBox(
                    height: 224,
                    child: PageView.builder(
                      controller: _pager,
                      itemCount: featured.length,
                      onPageChanged: (i) => setState(() => _page = i),
                      itemBuilder: (context, i) =>
                          FeaturedCard(event: featured[i]),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    for (var i = 0; i < featured.length; i++)
                      AnimatedContainer(
                        duration: const Duration(milliseconds: 250),
                        margin: const EdgeInsets.symmetric(horizontal: 3),
                        width: i == _page ? 22 : 7,
                        height: 7,
                        decoration: BoxDecoration(
                          gradient: i == _page ? Pal.brand : null,
                          color: i == _page
                              ? null
                              : scheme.onSurface.withValues(alpha: 0.25),
                          borderRadius: BorderRadius.circular(4),
                        ),
                      ),
                  ],
                ),
              ],
              if (data.upcoming.isNotEmpty) ...[
                const SizedBox(height: 26),
                const _SectionTitle('PRÓXIMOS EVENTOS'),
                const SizedBox(height: 12),
                SizedBox(
                  height: 40,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    children: [
                      _LeagueChip(
                        label: 'Todos',
                        selected: _league == null,
                        onTap: () => setState(() => _league = null),
                      ),
                      for (final l in chips)
                        _LeagueChip(
                          label: l,
                          selected: _league == l,
                          onTap: () => setState(() => _league = l),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                for (var i = 0; i < shown.length; i++)
                  _FadeSlide(
                    key: ValueKey('${shown[i].id}-$_league-$i'),
                    delay: Duration(milliseconds: 50 * math.min(i, 8)),
                    child: EventCard(event: shown[i]),
                  ),
              ],
            ],
          ),
        );
      },
    );
  }
}

class _LeagueChip extends StatelessWidget {
  const _LeagueChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = _isDark(context);
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          padding: const EdgeInsets.symmetric(horizontal: 16),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            gradient: selected ? Pal.brand : null,
            color: selected
                ? null
                : (dark
                    ? Colors.white.withValues(alpha: 0.08)
                    : Colors.black.withValues(alpha: 0.06)),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: selected
                  ? Pal.ink
                  : Theme.of(context).colorScheme.onSurface,
            ),
          ),
        ),
      ),
    );
  }
}

class FeaturedCard extends StatelessWidget {
  const FeaturedCard({super.key, required this.event});

  final LiveEvent event;

  @override
  Widget build(BuildContext context) {
    final c1 = _hueColor(event.league, l: 0.40);
    final c2 = _hueColor('${event.league}#', l: 0.20);
    final time = event.kickoff;
    final url = event.embedUrl;
    return _Pressable(
      onTap: url == null ? null : () => openPlayer(context, event),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 6),
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(26),
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [c1, c2],
          ),
          border: Border.all(color: Colors.white.withValues(alpha: 0.18)),
          boxShadow: [
            BoxShadow(
              color: c1.withValues(alpha: 0.35),
              blurRadius: 24,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Stack(
          children: [
            Positioned(
              right: -40,
              top: -40,
              child: Container(
                width: 160,
                height: 160,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.white.withValues(alpha: 0.08),
                ),
              ),
            ),
            Positioned(
              left: -30,
              bottom: -50,
              child: Container(
                width: 130,
                height: 130,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.white.withValues(alpha: 0.05),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          event.league.toUpperCase(),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.75),
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1.2,
                          ),
                        ),
                      ),
                      if (event.live)
                        LivePill(minute: _minuteLabel(event.minute))
                      else if (time != null)
                        _TimeTag(time, onDark: true),
                    ],
                  ),
                  const Spacer(),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(child: _TeamCol(name: event.home)),
                      _ScoreBox(score: event.score),
                      Expanded(child: _TeamCol(name: event.away)),
                    ],
                  ),
                  const Spacer(),
                  Align(
                    alignment: Alignment.center,
                    child: url != null
                        ? const _WatchButton(label: 'Ver en vivo')
                        : Text(
                            'Señal no disponible todavía',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.7),
                              fontSize: 12,
                            ),
                          ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Listado de eventos
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
      widget.withDate ? '${widget.path}?date=${_ymd(_date)}' : widget.path;

  @override
  void initState() {
    super.initState();
    _future = LiveoApi.events(_path);
  }

  Future<void> _reload() async {
    final f = LiveoApi.events(_path);
    setState(() {
      _future = f;
    });
    try {
      await f;
    } catch (_) {
      // El error se muestra en el FutureBuilder.
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _date = picked;
      _future = LiveoApi.events(_path);
    });
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<LiveEvent>>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const _Skeleton(hero: false);
        }
        if (snap.hasError) {
          return _ErrorView(error: '${snap.error}', onRetry: _reload);
        }
        final events = snap.data ?? const <LiveEvent>[];
        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 110),
            children: [
              Row(
                children: [
                  Expanded(
                    child: GradientText(
                      widget.title,
                      style: const TextStyle(
                        fontSize: 30,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1,
                      ),
                    ),
                  ),
                  if (widget.withDate)
                    _Pressable(
                      onTap: _pickDate,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 9,
                        ),
                        decoration: _glass(context, radius: 20),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(
                              Icons.calendar_today_rounded,
                              size: 15,
                              color: Pal.cyan,
                            ),
                            const SizedBox(width: 8),
                            Text(
                              _ymd(_date),
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              if (events.isEmpty)
                const _EmptyState(
                  icon: Icons.event_busy_rounded,
                  text: 'No hay eventos disponibles en este momento.',
                ),
              for (var i = 0; i < events.length; i++)
                _FadeSlide(
                  key: ValueKey('${events[i].id}-$i-${_ymd(_date)}'),
                  delay: Duration(milliseconds: 40 * math.min(i, 8)),
                  child: EventCard(event: events[i]),
                ),
            ],
          ),
        );
      },
    );
  }
}

class EventCard extends StatelessWidget {
  const EventCard({super.key, required this.event});

  final LiveEvent event;

  @override
  Widget build(BuildContext context) {
    final time = event.kickoff;
    final url = event.embedUrl;
    final scheme = Theme.of(context).colorScheme;
    final textColor = scheme.onSurface;
    return _Pressable(
      onTap: url == null ? null : () => openPlayer(context, event),
      child: Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(16),
        decoration: _glass(context, glow: event.live ? Pal.pink : null),
        child: Column(
          children: [
            Row(
              children: [
                Container(
                  width: 4,
                  height: 14,
                  decoration: BoxDecoration(
                    color: _hueColor(event.league),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    event.league,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context)
                        .textTheme
                        .labelMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                if (event.live)
                  LivePill(minute: _minuteLabel(event.minute))
                else if (time != null)
                  _TimeTag(time),
              ],
            ),
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(child: _TeamCol(name: event.home, color: textColor)),
                _ScoreBox(score: event.score, color: textColor),
                Expanded(child: _TeamCol(name: event.away, color: textColor)),
              ],
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                const Icon(Icons.schedule_rounded, size: 16, color: Pal.cyan),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    time == null ? event.status : _fmtTime(time),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
                if (url != null)
                  const _WatchButton(label: 'Ver')
                else
                  Text(
                    'Sin señal',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Reproductor (el embed de Matchora dentro de un iframe, en la misma app)
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

class PlayerPage extends StatefulWidget {
  const PlayerPage({
    super.key,
    required this.url,
    required this.title,
    this.live = false,
    this.minute,
  });

  final String url;
  final String title;
  final bool live;
  final String? minute;

  @override
  State<PlayerPage> createState() => _PlayerPageState();
}

class _PlayerPageState extends State<PlayerPage> {
  late final WebViewController controller;
  late final String _base = '${Uri.parse(apiBase).origin}/';
  Timer? _hideTimer;
  bool _loading = true;
  bool _failed = false;
  bool _full = false;

  @override
  void initState() {
    super.initState();
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
    // Solo se permite la página contenedora; así el reproductor no puede
    // sacar a la persona de la app.
    if (r.url == _base || r.url.startsWith('about:') || r.url.startsWith('data:')) {
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

  Future<void> _setFull(bool v) async {
    setState(() => _full = v);
    await SystemChrome.setPreferredOrientations(
      v
          ? const [
              DeviceOrientation.landscapeLeft,
              DeviceOrientation.landscapeRight,
            ]
          : const [
              DeviceOrientation.portraitUp,
              DeviceOrientation.portraitDown,
              DeviceOrientation.landscapeLeft,
              DeviceOrientation.landscapeRight,
            ],
    );
    await SystemChrome.setEnabledSystemUIMode(
      v ? SystemUiMode.immersiveSticky : SystemUiMode.edgeToEdge,
    );
  }

  @override
  void dispose() {
    _hideTimer?.cancel();
    SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_full,
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop) _setFull(false);
      },
      child: Scaffold(
        backgroundColor: const Color(0xff070b14),
        body: Column(
          children: [
            if (!_full) _buildBar(context),
            Expanded(
              child: Stack(
                children: [
                  Positioned.fill(child: WebViewWidget(controller: controller)),
                  if (_full)
                    Positioned(
                      top: 8,
                      right: 8,
                      child: SafeArea(
                        child: IconButton.filledTonal(
                          tooltip: 'Salir de pantalla completa',
                          onPressed: () => _setFull(false),
                          icon: const Icon(Icons.fullscreen_exit_rounded),
                        ),
                      ),
                    ),
                  Positioned.fill(
                    child: IgnorePointer(
                      ignoring: !_loading,
                      child: AnimatedOpacity(
                        opacity: _loading ? 1 : 0,
                        duration: const Duration(milliseconds: 400),
                        child: Container(
                          color: const Color(0xff070b14),
                          alignment: Alignment.center,
                          child: const Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              SizedBox(
                                width: 46,
                                height: 46,
                                child: CircularProgressIndicator(
                                  color: Pal.lime,
                                  strokeWidth: 3,
                                ),
                              ),
                              SizedBox(height: 16),
                              Text(
                                'Cargando señal…',
                                style: TextStyle(color: Colors.white70),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  if (_failed)
                    Positioned.fill(
                      child: Container(
                        color: const Color(0xff070b14),
                        alignment: Alignment.center,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(
                              Icons.wifi_off_rounded,
                              size: 48,
                              color: Colors.white70,
                            ),
                            const SizedBox(height: 12),
                            const Text(
                              'No se pudo cargar el reproductor',
                              style: TextStyle(color: Colors.white),
                            ),
                            const SizedBox(height: 12),
                            FilledButton.icon(
                              onPressed: _reload,
                              icon: const Icon(Icons.refresh_rounded),
                              label: const Text('Reintentar'),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBar(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0xff111a33), Color(0xff0b1020)],
        ),
        border: Border(bottom: BorderSide(color: Color(0x2222d3ee))),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 4, 6),
          child: Row(
            children: [
              IconButton(
                tooltip: 'Volver',
                onPressed: () => Navigator.of(context).maybePop(),
                icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      widget.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 16,
                      ),
                    ),
                    const SizedBox(height: 2),
                    const Text(
                      'Reproductor integrado',
                      style: TextStyle(color: Colors.white54, fontSize: 11),
                    ),
                  ],
                ),
              ),
              if (widget.live) LivePill(minute: widget.minute),
              IconButton(
                tooltip: 'Recargar',
                onPressed: _reload,
                icon: const Icon(Icons.refresh_rounded, color: Colors.white),
              ),
              IconButton(
                tooltip: 'Pantalla completa',
                onPressed: () => _setFull(true),
                icon: const Icon(Icons.fullscreen_rounded, color: Colors.white),
              ),
              IconButton(
                tooltip: 'Chromecast',
                onPressed: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text(
                        'Cast estará disponible para fuentes compatibles con '
                        'Google Cast. El iframe del proveedor no expone un '
                        'stream directo.',
                      ),
                    ),
                  );
                },
                icon: const Icon(Icons.cast_rounded, color: Colors.white),
              ),
            ],
          ),
        ),
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
    final divider = Divider(
      height: 1,
      color: Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.08),
    );
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 110),
      children: [
        const GradientText(
          'Ajustes',
          style: TextStyle(
            fontSize: 30,
            fontWeight: FontWeight.w900,
            letterSpacing: 1,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          'Personaliza tu experiencia',
          style: TextStyle(
            color: Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.65),
          ),
        ),
        const SizedBox(height: 20),
        Container(
          decoration: _glass(context),
          child: SwitchListTile(
            title: const Text('Modo oscuro'),
            subtitle: const Text('Cambiar entre apariencia clara y oscura'),
            value: dark,
            onChanged: onTheme,
            secondary: Icon(dark ? Icons.dark_mode : Icons.light_mode),
          ),
        ),
        const SizedBox(height: 14),
        Container(
          decoration: _glass(context),
          child: Column(
            children: [
              const ListTile(
                leading: Icon(Icons.access_time_rounded),
                title: Text('Zona horaria'),
                subtitle: Text('Hora local del dispositivo'),
              ),
              divider,
              const ListTile(
                leading: Icon(Icons.dns_outlined),
                title: Text('Servidor'),
                subtitle: Text(apiBase),
              ),
              divider,
              const ListTile(
                leading: Icon(Icons.play_circle_outline_rounded),
                title: Text('Reproductor'),
                subtitle: Text('Matchora, integrado dentro de la app'),
              ),
              divider,
              const ListTile(
                leading: Icon(Icons.cast_rounded),
                title: Text('Chromecast'),
                subtitle: Text('Disponible cuando la fuente permita Google Cast'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(22),
            gradient: const LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [Pal.violet, Pal.cyan],
            ),
            boxShadow: [
              BoxShadow(
                color: Pal.violet.withValues(alpha: 0.35),
                blurRadius: 22,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: const Row(
            children: [
              LogoTile(size: 52),
              SizedBox(width: 14),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'LIVEO',
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 3,
                      fontSize: 18,
                    ),
                  ),
                  Text(
                    'Versión $liveoVersion',
                    style: TextStyle(color: Colors.white70),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}
