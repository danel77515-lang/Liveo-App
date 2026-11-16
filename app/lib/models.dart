import 'dart:convert';

import 'package:http/http.dart' as http;

/// URL base de la API de Liveo. Se define al compilar con
/// --dart-define=API_BASE_URL=http://IP/Liveo/api
const String apiBase = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://192.168.54.109/Liveo/api',
);

String _two(int n) => n.toString().padLeft(2, '0');

/// HH:mm
String fmtHm(DateTime t) => '${_two(t.hour)}:${_two(t.minute)}';

/// dd/MM
String fmtDm(DateTime t) => '${_two(t.day)}/${_two(t.month)}';

/// AAAA-MM-DD
String fmtYmd(DateTime d) => '${d.year}-${_two(d.month)}-${_two(d.day)}';

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

/// "22" -> "22'"; otros textos se dejan igual.
String? minuteLabel(String? m) {
  if (m == null || m.trim().isEmpty) return null;
  final t = m.trim();
  return int.tryParse(t) != null ? "$t'" : t;
}

/// Enlace m3u8 agregado desde el panel de administración.
class StreamSource {
  const StreamSource({
    required this.id,
    required this.label,
    required this.url,
    this.referer,
    this.userAgent,
  });

  final int id;
  final String label;
  final String url;
  final String? referer;
  final String? userAgent;

  /// true si el servidor del stream exige cabeceras (Chromecast no las envía).
  bool get needsHeaders => referer != null || userAgent != null;

  static StreamSource? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final url = raw['url'];
    if (url is! String) return null;
    final uri = Uri.tryParse(url);
    if (uri == null || (uri.scheme != 'http' && uri.scheme != 'https')) {
      return null;
    }
    String? opt(Object? v) {
      if (v is String && v.trim().isNotEmpty) return v.trim();
      return null;
    }

    final label = opt(raw['label']) ?? 'Fuente';
    return StreamSource(
      id: raw['id'] is num ? (raw['id'] as num).toInt() : 0,
      label: label,
      url: url,
      referer: opt(raw['referer']),
      userAgent: opt(raw['user_agent']),
    );
  }
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
    this.links = const <StreamSource>[],
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

  /// Enlaces m3u8 propios (reproductor integrado de la app).
  final List<StreamSource> links;

  String get title => '$home vs $away';

  /// Tiene alguna señal: enlace propio o reproductor de Matchora.
  bool get playable => links.isNotEmpty || embedUrl != null;

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

    final links = <StreamSource>[];
    final rawLinks = j['links'];
    if (rawLinks is List) {
      for (final l in rawLinks) {
        final s = StreamSource.tryParse(l);
        if (s != null) links.add(s);
      }
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
      links: links,
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
