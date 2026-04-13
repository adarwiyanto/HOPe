package id.my.hopenoodles.hopepos.ui

import android.webkit.JavascriptInterface
import android.util.Log
import org.json.JSONObject

class WebAppBridge(
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String?) -> BridgeResult,
    private val onOpenPrinterSettings: () -> Unit,
) {

    @JavascriptInterface
    fun printReceipt(payloadJson: String?): String {
        Log.d(TAG, "printReceipt() dipanggil dari JS")
        if (!isTrustedOrigin()) {
            Log.w(TAG, "printReceipt ditolak: UNTRUSTED_ORIGIN")
            return BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
        }
        return try {
            onPrintReceipt(payloadJson).toJson()
        } catch (e: Exception) {
            Log.e(TAG, "printReceipt exception", e)
            BridgeResult(false, "INTERNAL_ERROR", e.message ?: "Terjadi kesalahan").toJson()
        }
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        Log.d(TAG, "openPrinterSettings() dipanggil dari JS")
        if (!isTrustedOrigin()) {
            Log.w(TAG, "openPrinterSettings ditolak: UNTRUSTED_ORIGIN")
            return
        }
        runCatching {
            onOpenPrinterSettings()
        }.onFailure {
            Log.e(TAG, "openPrinterSettings gagal", it)
        }
    }

    @JavascriptInterface
    fun ping(): String = BridgeResult(true, "PONG", "pong").toJson()

    @JavascriptInterface
    fun isReady(): String = BridgeResult(true, "READY", "Android bridge siap").toJson()

    @JavascriptInterface
    fun isReadySimple(): Boolean = true

    data class BridgeResult(
        val ok: Boolean,
        val code: String,
        val message: String,
    ) {
        fun toJson(): String = JSONObject()
            .put("ok", ok)
            .put("code", code)
            .put("message", message)
            .toString()
    }

    companion object {
        private const val TAG = "WebAppBridge"
    }
}
