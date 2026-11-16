import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:liveo/main.dart';

void main() {
  test('LiveEvent.fromJson interpreta los campos básicos', () {
    final e = LiveEvent.fromJson({
      'id': 'a1',
      'league': {'name': 'Liga'},
      'home': 'Local FC',
      'away': 'Visitante FC',
      'kickoff': 1700000000,
      'live': true,
      'score': '1 - 0',
      'embed_url': 'https://example.com/embed/1',
    });
    expect(e.league, 'Liga');
    expect(e.home, 'Local FC');
    expect(e.live, isTrue);
    expect(e.score, '1 - 0');
    expect(e.kickoff, isNotNull);
    expect(e.embedUrl, 'https://example.com/embed/1');
  });

  test('LiveEvent.fromJson descarta embeds no http(s)', () {
    final e = LiveEvent.fromJson({'embed_url': 'javascript:alert(1)'});
    expect(e.embedUrl, isNull);
    expect(e.home, 'Local');
  });

  test('LiveEvent.fromJson lee el minuto del partido', () {
    final e = LiveEvent.fromJson({'id': 'm1', 'live': true, 'minute': 22});
    expect(e.minute, '22');
    expect(e.live, isTrue);
  });

  testWidgets('LiveoLogo se dibuja sin errores', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Center(child: LiveoLogo(size: 80))),
      ),
    );
    expect(find.byType(LiveoLogo), findsOneWidget);
  });

  test('LiveEvent.fromJson lee los enlaces m3u8 del panel', () {
    final e = LiveEvent.fromJson({
      'id': 'e1',
      'live': false,
      'links': [
        {'id': 3, 'label': 'HD', 'url': 'https://x.test/a.m3u8', 'referer': 'https://x.test/'},
        {'id': 4, 'label': 'Mala', 'url': 'ftp://x.test/a.m3u8'},
      ],
    });
    expect(e.links.length, 1);
    expect(e.links.first.label, 'HD');
    expect(e.links.first.needsHeaders, isTrue);
    expect(e.playable, isTrue);
    expect(e.embedUrl, isNull);
  });

  test('Un evento sin enlaces ni embed no es reproducible', () {
    final e = LiveEvent.fromJson({'id': 'e2'});
    expect(e.links, isEmpty);
    expect(e.playable, isFalse);
  });
}
