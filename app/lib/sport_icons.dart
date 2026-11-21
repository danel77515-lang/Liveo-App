import 'dart:convert';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:http/http.dart' as http;

import 'models.dart';

/// Íconos de deportes (Tabler Icons, MIT). El panel elige uno por deporte; si
/// el servidor no responde se usan los de fábrica.
class SportIcons {
  SportIcons._();

  /// Mismo nombre que el archivo `assets/sports/<clave>.svg`.
  static const String fallback = 'trophy';

  static const Map<String, String> _defaults = {
    'futbol': 'ball-football',
    'basquet': 'ball-basketball',
    'tenis': 'ball-tennis',
    'voleibol': 'ball-volleyball',
    'beisbol': 'ball-baseball',
    'rugby': 'ball-american-football',
    'futbol americano': 'ball-american-football',
    'automovilismo': 'car',
    'motociclismo': 'motorbike',
    'ciclismo': 'bike',
    'golf': 'golf',
    'boxeo': 'karate',
    'mma': 'karate',
  };

  static const Set<String> _available = {
    'ball-football',
    'ball-basketball',
    'ball-tennis',
    'ball-volleyball',
    'ball-baseball',
    'ball-american-football',
    'ball-bowling',
    'car',
    'bike',
    'motorbike',
    'golf',
    'karate',
    'swimming',
    'run',
    'horse',
    'trophy',
  };

  /// Fotos incluidas en la app (`assets/sports/photos/<clave>.webp`, ~40 KB):
  /// se ven al instante, sin descargar nada. Clave = nombre sin tildes.
  static const Map<String, String> _photos = {
    'futbol': 'futbol',
    'basquet': 'basquet',
    'baloncesto': 'basquet',
    'basketball': 'basquet',
    'nba': 'basquet',
    'tenis': 'tenis',
    'rugby': 'rugby',
    'ciclismo': 'ciclismo',
    'boxeo': 'boxeo',
    'mma': 'mma',
    'ufc': 'mma',
    'automovilismo': 'automovilismo',
    'formula 1': 'automovilismo',
    'f1': 'automovilismo',
    'motociclismo': 'motociclismo',
    'motogp': 'motociclismo',
  };

  static const List<String> _photoFiles = [
    'futbol',
    'basquet',
    'tenis',
    'rugby',
    'ciclismo',
    'boxeo',
    'mma',
    'automovilismo',
    'motociclismo',
  ];

  /// Ruta del asset con la foto de un deporte, o null si no hay.
  static String? photoFor(String name) {
    final k = _photos[keyOf(name)];
    return k == null ? null : 'assets/sports/photos/$k.webp';
  }

  /// Carga todas las fotos en memoria (llamar una vez al iniciar).
  static void precache(BuildContext context) {
    for (final k in _photoFiles) {
      precacheImage(
        ResizeImage(
          AssetImage('assets/sports/photos/$k.webp'),
          width: 360,
        ),
        context,
      );
    }
  }

  /// Cambia cuando llega la lista del servidor (para repintar).
  static final ValueNotifier<int> version = ValueNotifier<int>(0);

  static Map<String, String> _map = Map<String, String>.of(_defaults);

  /// Logos propios (URL) subidos desde el panel; tienen prioridad sobre el ícono.
  static Map<String, String> _logos = <String, String>{};

  static const String _from = 'áàäâéèëêíìïîóòöôúùüûñ';
  static const String _to = 'aaaaeeeeiiiioooouuuun';

  /// Minúsculas, sin tildes y sin espacios repetidos.
  static String keyOf(String name) {
    final b = StringBuffer();
    for (final ch in name.trim().toLowerCase().split('')) {
      final i = _from.indexOf(ch);
      b.write(i >= 0 ? _to[i] : ch);
    }
    return b.toString().replaceAll(RegExp(r'\s+'), ' ');
  }

  /// Clave del ícono (archivo SVG) de un deporte.
  static String forSport(String name) => _map[keyOf(name)] ?? fallback;

  /// URL del logo propio de un deporte, o null si usa el ícono.
  static String? logoFor(String name) {
    final u = _logos[keyOf(name)];
    return (u == null || u.isEmpty) ? null : u;
  }

  /// Pide la lista al servidor. Nunca lanza: ante un error deja la actual.
  static Future<void> load() async {
    try {
      final r = await http
          .get(
            Uri.parse('$apiBase/sports'),
            headers: {'Accept': 'application/json'},
          )
          .timeout(const Duration(seconds: 8));
      if (r.statusCode != 200) return;
      final data = jsonDecode(utf8.decode(r.bodyBytes));
      if (data is! Map || data['items'] is! List) return;
      final next = <String, String>{};
      final logos = <String, String>{};
      for (final e in data['items'] as List) {
        if (e is! Map) continue;
        final name = '${e['name'] ?? ''}'.trim();
        final icon = '${e['icon'] ?? ''}'.trim();
        if (name.isEmpty) continue;
        next[keyOf(name)] = _available.contains(icon) ? icon : fallback;
        final logo = '${e['logo'] ?? ''}'.trim();
        if (logo.startsWith('http://') || logo.startsWith('https://')) {
          logos[keyOf(name)] = logo;
        }
      }
      _map = next;
      _logos = logos;
      version.value++;
    } catch (_) {
      // Servidor antiguo o sin conexión: se mantienen los íconos de fábrica.
    }
  }
}

/// Dibuja un SVG de `assets/sports` con el color indicado.
class SvgIcon extends StatelessWidget {
  const SvgIcon(this.name, {super.key, this.size = 22, this.color});

  final String name;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? IconTheme.of(context).color ?? Colors.white;
    return SvgPicture.asset(
      'assets/sports/$name.svg',
      width: size,
      height: size,
      colorFilter: ColorFilter.mode(c, BlendMode.srcIn),
    );
  }
}

/// Imagen de un deporte que ocupa TODO el recuadro (sin márgenes): foto
/// incluida en la app, o el logo del panel (con caché en disco), o el ícono.
class SportPhoto extends StatelessWidget {
  const SportPhoto(this.sport, {super.key, this.iconColor});

  final String sport;
  final Color? iconColor;

  @override
  Widget build(BuildContext context) {
    final asset = SportIcons.photoFor(sport);
    if (asset != null) {
      return Image.asset(
        asset,
        fit: BoxFit.cover,
        cacheWidth: 360,
        gaplessPlayback: true,
        filterQuality: FilterQuality.medium,
        errorBuilder: (_, __, ___) => _icon(context),
      );
    }
    final logo = SportIcons.logoFor(sport);
    if (logo != null) {
      return CachedNetworkImage(
        imageUrl: logo,
        fit: BoxFit.cover,
        memCacheWidth: 360,
        fadeInDuration: Duration.zero,
        fadeOutDuration: Duration.zero,
        placeholder: (_, __) => _icon(context),
        errorWidget: (_, __, ___) => _icon(context),
      );
    }
    return _icon(context);
  }

  Widget _icon(BuildContext context) => LayoutBuilder(
        builder: (_, c) {
          final s = (c.biggest.shortestSide.isFinite ? c.biggest.shortestSide : 80) * 0.62;
          return Center(
            child: SvgIcon(
              SportIcons.forSport(sport),
              size: s,
              color: iconColor,
            ),
          );
        },
      );
}

/// Ícono de un deporte: su logo propio si el panel tiene uno, o el SVG elegido.
class SportGlyph extends StatelessWidget {
  const SportGlyph(this.sport, {super.key, this.size = 22, this.color});

  final String sport;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final svg = SvgIcon(SportIcons.forSport(sport), size: size, color: color);
    final logo = SportIcons.logoFor(sport);
    if (logo == null) return svg;
    return Image.network(
      logo,
      width: size,
      height: size,
      fit: BoxFit.contain,
      errorBuilder: (_, __, ___) => svg,
    );
  }
}
