package id.my.hopenoodles.hopepos.ui

import android.webkit.JavascriptInterface
import org.json.JSONObject

class WebAppBridge(
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String?) -> BridgeResult,
    private val onOpenPrinterSettings: () -> Unit,
) {

    @JavascriptInterface
    fun printReceipt(payloadJson: String?): String {
        if (!isTrustedOrigin()) {
            return BridgeResult(false, "UNTRUSTED_ORIGIN", "Origin tidak diizinkan").toJson()
        }
        return try {
            onPrintReceipt(payloadJson).toJson()
        } catch (e: Exception) {
            BridgeResult(false, "INTERNAL_ERROR", e.message ?: "Terjadi kesalahan").toJson()
        }
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        if (isTrustedOrigin()) {
            onOpenPrinterSettings()
        }
    }

    @JavascriptInterface
    fun ping(): String = BridgeResult(true, "PONG", "pong").toJson()

    @JavascriptInterface
    fun isReady(): String = BridgeResult(true, "READY", "Android bridge siap").toJson()

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
}
