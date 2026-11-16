import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// Puente con el código nativo de Android (MainActivity): imagen en imagen
/// (PiP) y pantalla siempre encendida mientras se reproduce.
class NativeBridge {
  NativeBridge._();

  static const MethodChannel _ch = MethodChannel('liveo/native');

  /// true mientras la app está en la ventana flotante de PiP.
  static final ValueNotifier<bool> inPip = ValueNotifier<bool>(false);

  static bool _ready = false;

  static void ensureInit() {
    if (_ready) return;
    _ready = true;
    _ch.setMethodCallHandler((call) async {
      if (call.method == 'pipChanged') {
        inPip.value = call.arguments == true;
      }
      return null;
    });
  }

  /// Entra en imagen en imagen. false si el dispositivo no lo permite.
  static Future<bool> enterPip() async {
    try {
      return (await _ch.invokeMethod<bool>('enterPip')) ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Si es true, al salir de la app (botón inicio) entra solo en PiP.
  static Future<void> setPipReady(bool v) async {
    try {
      await _ch.invokeMethod<void>('setPipReady', v);
    } catch (_) {}
  }

  static Future<void> keepScreenOn(bool v) async {
    try {
      await _ch.invokeMethod<void>('keepScreenOn', v);
    } catch (_) {}
  }
}

/// Orientación y barras del sistema para el reproductor.
///
/// Lleva la cuenta de pantallas que lo usan: al cambiar de un reproductor a
/// otro (reemplazo de ruta) no se restaura la orientación a mitad de camino.
class ScreenMode {
  ScreenMode._();

  static int _users = 0;

  static Future<void> landscape() async {
    _users++;
    await SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    await SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
  }

  static Future<void> restore() async {
    if (_users > 0) _users--;
    if (_users > 0) return;
    await SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    await SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
  }
}
