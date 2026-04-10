package id.my.hopenoodles.posbridge.data

import android.content.Context

class PrinterPrefs(context: Context) {
    private val prefs = context.getSharedPreferences("hope_print_bridge", Context.MODE_PRIVATE)

    fun savePrinter(name: String, mac: String) {
        prefs.edit().putString("printer_name", name).putString("printer_mac", mac).apply()
    }

    fun printerMac(): String? = prefs.getString("printer_mac", null)
    fun printerName(): String? = prefs.getString("printer_name", null)
}
