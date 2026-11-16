import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:webview_flutter/webview_flutter.dart';

/// URL base de la API de Liveo. Se define al compilar con
/// --dart-define=API_BASE_URL=http://IP/Liveo/api
const String apiBase = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://192.168.54.109/Liveo/api',
);

void main() => runApp(const LiveoApp());

// ---------------------------------------------------------------------------
// Utilidades y modelo
// ---------------------------------------------------------------------------

String _two(int n) => n.toString().padLeft(2, '0');

String _ymd(DateTime d) => '${d.year}-${_two(d.month)}-${_two(d.day)}';

String _fmtTime(DateTime t) =>
    '${_two(t.day)}/${_two(t.month)} · ${_two(t.hour)}:${_two(t.minute)}';

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
        useMaterial3: true,
      ),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xffa4e66a),
          brightness: Brightness.dark,
          surface: const Color(0xff101a2a),
        ),
        scaffoldBackgroundColor: const Color(0xff0b1422),
        useMaterial3: true,
      ),
      home: HomePage(dark: mode == ThemeMode.dark, onTheme: _toggleTheme),
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
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          'LIVEO',
          style: TextStyle(fontWeight: FontWeight.w900, letterSpacing: 2),
        ),
      ),
      body: IndexedStack(
        index: tab,
        children: [
          const EventsTab(
            path: 'schedule?watchable=1',
            title: 'EVENTOS PARA VER',
          ),
          const EventsTab(path: 'live', title: 'AHORA EN DIRECTO'),
          const EventsTab(path: 'schedule', title: 'AGENDA', withDate: true),
          SettingsPage(dark: widget.dark, onTheme: widget.onTheme),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (v) {
          setState(() {
            tab = v;
          });
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Inicio',
          ),
          NavigationDestination(icon: Icon(Icons.sensors), label: 'En vivo'),
          NavigationDestination(
            icon: Icon(Icons.calendar_month_outlined),
            selectedIcon: Icon(Icons.calendar_month),
            label: 'Agenda',
          ),
          NavigationDestination(icon: Icon(Icons.tune), label: 'Ajustes'),
        ],
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
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      widget.title,
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.w900),
                    ),
                  ),
                  if (widget.withDate)
                    TextButton.icon(
                      onPressed: _pickDate,
                      icon: const Icon(Icons.calendar_today, size: 16),
                      label: Text(_ymd(_date)),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              if (events.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 48),
                  child: Center(
                    child: Text('No hay eventos disponibles en este momento.'),
                  ),
                ),
              for (final e in events) EventCard(event: e),
            ],
          ),
        );
      },
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
            const Icon(Icons.cloud_off_outlined, size: 48),
            const SizedBox(height: 12),
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
            TextButton(onPressed: onRetry, child: const Text('Reintentar')),
          ],
        ),
      ),
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
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    event.league,
                    style: Theme.of(context).textTheme.labelMedium,
                  ),
                ),
                if (event.live)
                  const Chip(
                    avatar: Icon(Icons.circle, color: Colors.red, size: 9),
                    label: Text('EN VIVO'),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: Text(
                    event.home,
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Text(
                    event.score ?? 'vs',
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 18,
                    ),
                  ),
                ),
                Expanded(
                  child: Text(
                    event.away,
                    textAlign: TextAlign.end,
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Icon(
                  Icons.schedule,
                  size: 16,
                  color: Theme.of(context).colorScheme.primary,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(time == null ? event.status : _fmtTime(time)),
                ),
                if (url != null)
                  TextButton(
                    onPressed: () {
                      Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => PlayerPage(
                            url: url,
                            title: '${event.home} vs ${event.away}',
                          ),
                        ),
                      );
                    },
                    child: const Text('Ver evento'),
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
// Reproductor (WebView con el embed del proveedor)
// ---------------------------------------------------------------------------

class PlayerPage extends StatefulWidget {
  const PlayerPage({super.key, required this.url, required this.title});

  final String url;
  final String title;

  @override
  State<PlayerPage> createState() => _PlayerPageState();
}

class _PlayerPageState extends State<PlayerPage> {
  late final WebViewController controller;

  @override
  void initState() {
    super.initState();
    controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onNavigationRequest: (request) {
            final u = Uri.tryParse(request.url);
            final ok = u != null && (u.scheme == 'http' || u.scheme == 'https');
            return ok ? NavigationDecision.navigate : NavigationDecision.prevent;
          },
        ),
      )
      ..loadRequest(Uri.parse(widget.url));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            tooltip: 'Chromecast',
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(
                  content: Text(
                    'Cast estará disponible para fuentes compatibles con '
                    'Google Cast. El iframe del proveedor no expone un stream '
                    'directo.',
                  ),
                ),
              );
            },
            icon: const Icon(Icons.cast),
          ),
        ],
      ),
      body: WebViewWidget(controller: controller),
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
      padding: const EdgeInsets.all(20),
      children: [
        Text(
          'Preferencias',
          style: Theme.of(context)
              .textTheme
              .headlineSmall
              ?.copyWith(fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 16),
        SwitchListTile(
          title: const Text('Modo oscuro'),
          subtitle: const Text('Cambiar entre apariencia clara y oscura'),
          value: dark,
          onChanged: onTheme,
          secondary: Icon(dark ? Icons.dark_mode : Icons.light_mode),
        ),
        const ListTile(
          leading: Icon(Icons.access_time),
          title: Text('Zona horaria'),
          subtitle: Text('Hora local del dispositivo'),
        ),
        const ListTile(
          leading: Icon(Icons.dns_outlined),
          title: Text('Servidor'),
          subtitle: Text(apiBase),
        ),
        const ListTile(
          leading: Icon(Icons.cast),
          title: Text('Chromecast'),
          subtitle: Text('Disponible cuando la fuente permita Google Cast'),
        ),
        const ListTile(
          leading: Icon(Icons.info_outline),
          title: Text('Liveo'),
          subtitle: Text('Versión 1.0.0'),
        ),
      ],
    );
  }
}
