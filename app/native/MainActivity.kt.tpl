package __PKG__

import android.content.pm.PackageManager
import android.content.res.Configuration
import android.app.PictureInPictureParams
import android.os.Build
import android.util.Rational
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * MainActivity de Liveo: imagen en imagen (PiP) y pantalla siempre encendida
 * para el reproductor integrado. Este archivo lo copia el workflow sobre el
 * MainActivity.kt que genera "flutter create" (ver app/native/).
 */
class MainActivity : FlutterActivity() {
    private var channel: MethodChannel? = null
    private var pipReady = false

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val ch = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "liveo/native")
        channel = ch
        ch.setMethodCallHandler { call, result ->
            when (call.method) {
                "enterPip" -> result.success(enterPip())
                "setPipReady" -> {
                    pipReady = (call.arguments as? Boolean) ?: false
                    result.success(null)
                }
                "keepScreenOn" -> {
                    val on = (call.arguments as? Boolean) ?: false
                    if (on) {
                        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    } else {
                        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    }
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }

    private fun enterPip(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return false
        if (!packageManager.hasSystemFeature(PackageManager.FEATURE_PICTURE_IN_PICTURE)) return false
        return try {
            val params = PictureInPictureParams.Builder()
                .setAspectRatio(Rational(16, 9))
                .build()
            enterPictureInPictureMode(params)
        } catch (e: Exception) {
            false
        }
    }

    // Al pulsar "inicio" con el reproductor abierto, pasa solo a PiP.
    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        if (pipReady) enterPip()
    }

    override fun onPictureInPictureModeChanged(
        isInPictureInPictureMode: Boolean,
        newConfig: Configuration
    ) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode, newConfig)
        channel?.invokeMethod("pipChanged", isInPictureInPictureMode)
    }
}
