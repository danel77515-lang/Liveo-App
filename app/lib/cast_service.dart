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

import 'models.dart' show StreamSource, apiBase;

/// Chromecast (Google Cast) con el receptor multimedia por defecto, que
/// reproduce listas HLS (m3u8) públicas en el televisor.
class CastService {
  CastService._();

  static bool ready = false;

  static Future<void> init() async {
    if (!Platform.isAndroid) return;
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

  static void startDiscovery() {
    if (ready) GoogleCastDiscoveryManager.instance.startDiscovery();
  }

  static void stopDiscovery() {
    if (ready) GoogleCastDiscoveryManager.instance.stopDiscovery();
  }

  /// El televisor no puede abrir «localhost»: en ese caso se le manda la URL original.
  static bool get _proxyAvailable {
    final h = (Uri.tryParse(apiBase)?.host ?? '').toLowerCase();
    return h.isNotEmpty && h != 'localhost' && !h.startsWith('127.');
  }

  /// true si la señal se transmite a través de Liveo (backend/api/cast.php).
  static bool usesProxy(StreamSource s) => s.id > 0 && _proxyAvailable;

  /// URL que recibe el televisor. Pasando por Liveo, el backend le pide la señal al servidor original
  /// con el Referer y el User-Agent del panel y agrega CORS; el Chromecast solo habla con Liveo.
  static String urlFor(StreamSource s) =>
      usesProxy(s) ? '$apiBase/cast.php?s=${s.id}' : s.url;

  /// Espera (hasta 15 s) a que la sesión con el televisor quede conectada.
  static Future<bool> _waitConnected() async {
    final limit = DateTime.now().add(const Duration(seconds: 15));
    while (!connected) {
      if (DateTime.now().isAfter(limit)) return false;
      await Future<void>.delayed(const Duration(milliseconds: 250));
    }
    // Deja que el receptor del televisor termine de iniciar.
    await Future<void>.delayed(const Duration(milliseconds: 500));
    return true;
  }

  static Future<void> start(GoogleCastDevice device, String url) async {
    await GoogleCastSessionManager.instance.startSessionWithDevice(device);
    final ok = await _waitConnected();
    try {
      await load(url);
    } catch (_) {
      // El receptor puede tardar un poco más en estar listo: un segundo intento.
      await Future<void>.delayed(const Duration(milliseconds: 1500));
      try {
        await load(url);
      } catch (e) {
        throw Exception(
          ok
              ? 'El televisor no aceptó la señal ($e).'
              : 'No se pudo conectar con el televisor: revisa que el celular y el Chromecast estén en la misma red Wi-Fi.',
        );
      }
    }
  }

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
