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
    });
    expect(e.league, 'Liga');
    expect(e.home, 'Local FC');
    expect(e.live, isTrue);
    expect(e.score, '1 - 0');
    expect(e.kickoff, isNotNull);
  });

  test('LiveEvent.fromJson lee deporte, escudos y hora a confirmar', () {
    final e = LiveEvent.fromJson({
      'id': 'manual-1',
      'sport': 'Fútbol',
      'home': 'Chelsea',
      'away': 'Leeds United',
      'home_logo': 'https://x.test/chelsea.png',
      'away_logo': 'javascript:alert(1)',
      'time_tbc': true,
      'live': false,
    });
    expect(e.sport, 'Fútbol');
    expect(e.homeLogo, 'https://x.test/chelsea.png');
    expect(e.awayLogo, isNull);
    expect(e.timeTbc, isTrue);
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
        {
          'id': 3,
          'label': 'HD',
          'url': 'https://x.test/a.m3u8',
          'referer': 'https://x.test/',
        },
        {'id': 4, 'label': 'Mala', 'url': 'ftp://x.test/a.m3u8'},
      ],
    });
    expect(e.links.length, 1);
    expect(e.links.first.label, 'HD');
    expect(e.links.first.needsHeaders, isTrue);
    expect(e.playable, isTrue);
  });

  test('Un evento sin enlaces no es reproducible', () {
    final e = LiveEvent.fromJson({'id': 'e2'});
    expect(e.links, isEmpty);
    expect(e.playable, isFalse);
  });

  test('LiveEvent.fromJson distingue enlaces m3u8 e iframe', () {
    final e = LiveEvent.fromJson({
      'id': 'e3',
      'links': [
        {'id': 1, 'label': 'HD', 'url': 'https://x.test/a.m3u8', 'type': 'hls'},
        {'id': 2, 'label': 'Web', 'url': 'https://x.test/embed/9', 'type': 'iframe'},
      ],
    });
    expect(e.links.length, 2);
    expect(e.links[0].isIframe, isFalse);
    expect(e.links[1].isIframe, isTrue);
    expect(e.playable, isTrue);
  });
}
