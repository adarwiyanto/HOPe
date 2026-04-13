package id.my.hopenoodles.posbridge.ui

import android.webkit.JavascriptInterface

class WebAppBridge(
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String) -> BridgeResult,
    private val onOpenPrinterSettings: () -> Unit,
) {
    data class BridgeResult(
        val ok: Boolean,
        val code: String,
        val message: String,
    ) {
        fun asJson(): String {
            return "{\"ok\":${if (ok) "true" else "false"},\"code\":\"${escape(code)}\",\"message\":\"${escape(message)}\"}"
        }

        private fun escape(value: String): String {
            return value.replace("\\", "\\\\").replace("\"", "\\\"")
        }
    }

    @JavascriptInterface
    fun printReceipt(payloadJson: String?): String {
        if (!isTrustedOrigin()) {
            return BridgeResult(false, "UNTRUSTED_ORIGIN", "Bridge hanya tersedia untuk domain HOPe POS.").asJson()
        }
        val payload = payloadJson?.trim().orEmpty()
        if (payload.isBlank()) {
            return BridgeResult(false, "INVALID_PAYLOAD", "Data receipt kosong.").asJson()
        }
        return runCatching { onPrintReceipt(payload).asJson() }
            .getOrElse {
                BridgeResult(false, "BRIDGE_EXCEPTION", "Terjadi kesalahan bridge Android.").asJson()
            }
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        if (!isTrustedOrigin()) return
        onOpenPrinterSettings()
    }

    @JavascriptInterface
    fun ping(): String = if (isTrustedOrigin()) "ok" else "blocked"

    @JavascriptInterface
    fun isReady(): String = if (isTrustedOrigin()) "1" else "0"
}
