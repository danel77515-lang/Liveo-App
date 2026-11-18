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

/// Diferencia entre el reloj del servidor y el del teléfono (el servidor manda
/// `server_time`). Así las cuentas regresivas no dependen de la hora del celu.
Duration serverOffset = Duration.zero;

/// Hora actual corregida con el reloj del servidor.
DateTime syncedNow() => DateTime.now().add(serverOffset);

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
    this.sport = '',
    this.homeLogo,
    this.awayLogo,
    this.timeTbc = false,
    this.minute,
    this.links = const <StreamSource>[],
    this.liveLock = false,
    this.stateLabel,
    this.customTitle,
  });

  final String id;
  final String league;
  final String home;
  final String away;
  final String? score;
  final bool live;
  final DateTime? kickoff;
  final String status;
  final String? minute;

  /// Deporte (para el ícono de la tarjeta) y escudos opcionales.
  final String sport;
  final String? homeLogo;
  final String? awayLogo;

  /// true = solo se conoce la fecha; la hora está por confirmar.
  final bool timeTbc;

  /// Enlaces m3u8 propios (reproductor integrado de la app).
  final List<StreamSource> links;

  /// true = el estado se fijó a mano en el panel (evento propio): la app no
  /// lo cambia por horario y confía en la marca `live` del servidor.
  final bool liveLock;

  /// "Finalizado", "Postergado" o "Cancelado" (eventos propios).
  final String? stateLabel;

  /// Título escrito a mano en el panel (solo eventos propios).
  final String? customTitle;

  String get title =>
      customTitle ?? (away.isEmpty ? home : '$home vs $away');

  /// En vivo "de verdad", mirando la hora y no solo la marca del servidor:
  /// - el servidor lo marca en vivo pero su hora de inicio todavía no llegó
  ///   -> NO está en vivo (se muestra la cuenta regresiva);
  /// - su hora llegó hace poco (hasta 10 min) y el servidor aún no lo marcó
  ///   -> pasa a en vivo solo, sin esperar al refresco;
  /// - sin hora de inicio se confía en el servidor.
  bool get liveNow {
    if (stateLabel != null) return false;
    if (liveLock) return live;
    final k = kickoff;
    if (k == null) return live;
    final now = syncedNow();
    if (k.isAfter(now)) return false;
    if (live) return true;
    return now.difference(k) <= const Duration(minutes: 10);
  }

  /// Tiene alguna señal (enlace m3u8 cargado desde el panel).
  bool get playable => links.isNotEmpty;

  factory LiveEvent.fromJson(Map<String, dynamic> j) {
    String? logo(Object? v) {
      if (v is! String) return null;
      final uri = Uri.tryParse(v.trim());
      if (uri != null && (uri.scheme == 'http' || uri.scheme == 'https')) {
        return v.trim();
      }
      return null;
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

    String? stateLabel;
    final rawState = j['state_label'];
    if (rawState is String && rawState.trim().isNotEmpty) {
      stateLabel = rawState.trim();
    }
    String? customTitle;
    final rawTitle = j['title'];
    if (_truthy(j['manual']) &&
        rawTitle is String &&
        rawTitle.trim().isNotEmpty) {
      customTitle = rawTitle.trim();
    }

    return LiveEvent(
      id: '${j['id'] ?? ''}',
      league: _name(j['league'], 'Deporte'),
      home: _name(j['home'], 'Local'),
      away: _truthy(j['single_title']) ? '' : _name(j['away'], 'Visitante'),
      score: score,
      live: _truthy(j['live']),
      kickoff: _time(j['kickoff']),
      status: _name(j['status'], 'Horario a confirmar'),
      sport: _name(j['sport'], ''),
      homeLogo: logo(j['home_logo']),
      awayLogo: logo(j['away_logo']),
      timeTbc: _truthy(j['time_tbc']),
      minute: minute,
      links: links,
      liveLock: _truthy(j['live_lock']),
      stateLabel: stateLabel,
      customTitle: customTitle,
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
    final st = data['server_time'];
    if (st is num && st > 0) {
      final server =
          DateTime.fromMillisecondsSinceEpoch(st.toInt() * 1000, isUtc: true);
      serverOffset = server.difference(DateTime.now().toUtc());
    }
    final list = data['events'];
    if (list is! List) return const <LiveEvent>[];
    return list
        .whereType<Map>()
        .map((e) => LiveEvent.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }
}
