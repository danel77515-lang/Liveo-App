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
      .instance.currentSessionStream
      .map((_) => connected);

  static Stream<List<GoogleCastDevice>> get devices =>
      GoogleCastDiscoveryManager.instance.devicesStream;

  static void startDiscovery() {
    if (ready) GoogleCastDiscoveryManager.instance.startDiscovery();
  }

  static void stopDiscovery() {
    if (ready) GoogleCastDiscoveryManager.instance.stopDiscovery();
  }

  static Future<void> start(GoogleCastDevice device, String url) async {
    await GoogleCastSessionManager.instance.startSessionWithDevice(device);
    await load(url);
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
