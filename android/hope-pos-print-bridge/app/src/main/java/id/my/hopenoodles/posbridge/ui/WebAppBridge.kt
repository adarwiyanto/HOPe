package id.my.hopenoodles.posbridge.ui

import android.webkit.JavascriptInterface

class WebAppBridge(
    private val isTrustedOrigin: () -> Boolean,
    private val onPrintReceipt: (String, String?) -> Unit,
    private val onOpenPrinterSettings: () -> Unit,
) {
    @JavascriptInterface
    fun printReceipt(html: String, metaJson: String?) {
        if (!isTrustedOrigin()) return
        onPrintReceipt(html, metaJson)
    }

    @JavascriptInterface
    fun openPrinterSettings() {
        if (!isTrustedOrigin()) return
        onOpenPrinterSettings()
    }

    @JavascriptInterface
    fun ping(): String = "ok"
}
