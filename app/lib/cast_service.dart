import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_chrome_cast/flutter_chrome_cast.dart'
    show
        CastMediaStreamType,
        GoogleCastConnectState,
        GoogleCastContext,
        GoogleCastDevice,
        GoogleCastDiscoveryCriteria,
        GoogleCastDiscoveryManager,
        GoogleCastMediaInformationIOS,
        GoogleCastOptionsAndroid,
        GoogleCastRemoteMediaClient,
        GoogleCastSessionManager;

/// Chromecast (Google Cast) con el receptor multimedia por defecto, que
/// reproduce listas HLS (m3u8) públicas en el televisor.
class CastService {
  CastService._();

  static bool ready = false;

  /// Motivo por el que Chromecast no arrancó (se muestra al usuario).
  static String? initError;

  static Future<void> init() async {
    if (!Platform.isAndroid) {
      initError = 'Solo disponible en Android.';
      return;
    }
    try {
      const appId = GoogleCastDiscoveryCriteria.kDefaultApplicationId;
      await GoogleCastContext.instance.setSharedInstanceWithOptions(
        GoogleCastOptionsAndroid(
          appId: appId,
          stopCastingOnAppTerminated: true,
        ),
      );
      ready = true;
    } catch (e) {
      initError = '$e';
      debugPrint('Chromecast no disponible: $e');
    }
  }

  static bool get connected =>
      ready &&
      GoogleCastSessionManager.instance.connectionState ==
          GoogleCastConnectState.connected;

  /// Emite true/false cuando cambia la sesión de transmisión.
  static Stream<bool> get connectedStream => GoogleCastSessionManager
      .instance
      .currentSessionStream
      .map((_) => connected);

  static Stream<List<GoogleCastDevice>> get devices =>
      GoogleCastDiscoveryManager.instance.devicesStream;

  /// Búsqueda activa: la pasiva de Android suele no encontrar receptores
  /// (o tardar mucho) mientras se muestra el selector.
  static void startDiscovery() {
    if (ready) {
      GoogleCastDiscoveryManager.instance.startDiscovery(activeScan: true);
    }
  }

  static void stopDiscovery() {
    if (ready) GoogleCastDiscoveryManager.instance.stopDiscovery();
  }

  static Future<void> start(GoogleCastDevice device, String url) async {
    // En Android startSessionWithDevice solo selecciona la ruta y vuelve al
    // instante: la sesión se conecta después. Si se carga el video antes, el
    // plugin no hace nada (no hay RemoteMediaClient) y el televisor queda
    // en la pantalla de inicio sin reproducir. Hay que esperar la conexión.
    await GoogleCastSessionManager.instance.startSessionWithDevice(device);
    await _waitConnected();
    await _loadVerified(url);
  }

  /// Carga la fuente y comprueba que el televisor la recibió (llega un estado
  /// de reproducción). Si el plugin la ignoró porque el cliente multimedia aún
  /// no estaba listo, reintenta.
  static Future<void> _loadVerified(String url) async {
    const attempts = 4;
    for (var i = 0; i < attempts; i++) {
      // Un estado viejo (de una carga anterior) no cuenta como confirmación.
      final fresh = GoogleCastRemoteMediaClient.instance.mediaStatusStream
          .skip(GoogleCastRemoteMediaClient.instance.mediaStatus == null ? 0 : 1)
          .firstWhere((st) => st != null);
      try {
        await load(url);
      } catch (e) {
        debugPrint('loadMedia intento ${i + 1} falló: $e');
      }
      try {
        await fresh.timeout(const Duration(seconds: 4));
        return;
      } on TimeoutException {
        await Future<void>.delayed(const Duration(milliseconds: 500));
      }
    }
    throw TimeoutException(
      'El Chromecast se conectó pero no recibió el video. Prueba otra fuente.',
    );
  }

  static Future<void> _waitConnected() async {
    if (!connected) {
      final done = Completer<void>();
      final sub = GoogleCastSessionManager.instance.currentSessionStream
          .listen((_) {
        if (connected && !done.isCompleted) done.complete();
      });
      try {
        await done.future.timeout(
          const Duration(seconds: 20),
          onTimeout: () => throw TimeoutException(
            'El Chromecast no respondió. Revisa que el teléfono y el '
            'Chromecast estén en la misma red Wi-Fi.',
          ),
        );
      } finally {
        await sub.cancel();
      }
    }
    // El cliente multimedia se registra un instante después de conectar.
    await Future<void>.delayed(const Duration(milliseconds: 500));
  }

  /// Avisa (una vez por carga) si el receptor no pudo reproducir el stream.
  static Stream<String> get playbackErrors => GoogleCastRemoteMediaClient
      .instance
      .mediaStatusStream
      .where((s) => s?.idleReason?.name == 'error')
      .map((_) => 'El televisor no pudo reproducir esta fuente '
          '(el servidor del stream puede bloquear Chromecast).');

  static Future<void> load(String url) {
    return GoogleCastRemoteMediaClient.instance.loadMedia(
      GoogleCastMediaInformationIOS(
        contentId: url,
        streamType: CastMediaStreamType.live,
        contentUrl: Uri.parse(url),
        contentType: 'application/x-mpegURL',
      ),
      autoPlay: true,
    );
  }

  static Future<void> play() => GoogleCastRemoteMediaClient.instance.play();

  static Future<void> pause() => GoogleCastRemoteMediaClient.instance.pause();

  static Future<void> stop() async {
    try {
      await GoogleCastSessionManager.instance.endSessionAndStopCasting();
    } catch (e) {
      debugPrint('No se pudo detener la transmisión: $e');
    }
  }
}
