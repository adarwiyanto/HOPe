package id.my.hopenoodles.posbridge.ui

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.my.hopenoodles.posbridge.bluetooth.BluetoothPrinterManager
import id.my.hopenoodles.posbridge.bluetooth.EscPosFormatter
import id.my.hopenoodles.posbridge.data.PrinterPrefs
import id.my.hopenoodles.posbridge.data.ReceiptItem
import id.my.hopenoodles.posbridge.data.ReceiptPayload
import id.my.hopenoodles.posbridge.databinding.ActivityMainBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: PrinterPrefs
    private lateinit var btManager: BluetoothPrinterManager
    private val http = OkHttpClient()

    private val loginUrl = "https://hopenoodles.my.id/adm.php"
    private val posUrl = "https://hopenoodles.my.id/pos/index.php"
    private val trustedHost = "hopenoodles.my.id"

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {}

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = PrinterPrefs(this)
        btManager = BluetoothPrinterManager(this)

        setupWebView()
        ensureBluetoothPermissions()
        autoConnectLastPrinter()
        if (savedInstanceState == null) {
            binding.webView.loadUrl(loginUrl)
        }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                when {
                    binding.webView.canGoBack() -> binding.webView.goBack()
                    isOnPosRoot(binding.webView.url) -> showExitConfirmation()
                    else -> finish()
                }
            }
        })
    }

    private fun setupWebView() {
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(binding.webView, true)

        binding.webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            allowFileAccess = false
            javaScriptCanOpenWindowsAutomatically = false
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            val originalUa = userAgentString ?: ""
            userAgentString = "$originalUa HOPePOSAndroidWebView/1.0"
        }

        binding.webView.addJavascriptInterface(
            WebAppBridge(
                isTrustedOrigin = { isTrustedUrl(binding.webView.url) },
                onPrintReceipt = { payloadJson -> submitReceiptPrint(payloadJson) },
                onOpenPrinterSettings = { openPrinterSettings() },
            ),
            "AndroidBridge"
        )

        binding.webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                binding.webProgress.progress = newProgress
                binding.webProgress.visibility = if (newProgress >= 100) android.view.View.GONE else android.view.View.VISIBLE
            }
        }

        binding.webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url.toString()
                if (isTrustedUrl(url)) return false
                return try {
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                    true
                } catch (_: ActivityNotFoundException) {
                    false
                }
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                if (url.contains("/admin/dashboard.php")) {
                    view.loadUrl(posUrl)
                    return
                }
                if (isTrustedUrl(url)) {
                    view.evaluateJavascript(
                        "window.HopeAndroidBridgeInfo={ready:true,name:'AndroidBridge',host:'$trustedHost'};",
                        null
                    )
                }
            }
        }
    }

    private fun submitReceiptPrint(payloadJson: String): WebAppBridge.BridgeResult {
        ensureBluetoothPermissions()
        val mac = prefs.printerMac()
        if (mac.isNullOrBlank()) {
            runOnUiThread {
                toast("Printer belum dipilih")
                openPrinterSettings()
            }
            return WebAppBridge.BridgeResult(false, "PRINTER_NOT_SELECTED", "Printer belum dipilih.")
        }
        if (!btManager.isBluetoothEnabled()) {
            runOnUiThread {
                toast("Bluetooth belum aktif")
                openPrinterSettings()
            }
            return WebAppBridge.BridgeResult(false, "BLUETOOTH_OFF", "Bluetooth belum aktif.")
        }

        lifecycleScope.launch {
            val error = withContext(Dispatchers.IO) {
                runCatching {
                    val payload = parseReceiptPayload(payloadJson)
                    val logo = payload.logo_url?.takeIf { it.isNotBlank() }?.let { fetchBitmap(it) }
                    val bytes = EscPosFormatter.formatFromParsed(payload.toParsedReceipt(), logo)
                    btManager.print(mac, bytes)
                }.exceptionOrNull()?.message
            }

            if (error == null) {
                toast("Print berhasil")
            } else {
                toast("Print gagal: $error")
                openPrinterSettings()
            }
        }

        return WebAppBridge.BridgeResult(true, "QUEUED", "Data receipt dikirim ke Android bridge.")
    }

    private fun parseReceiptPayload(payloadJson: String): ReceiptPayload {
        val obj = JSONObject(payloadJson)
        val itemsArray = obj.optJSONArray("items") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsArray.length()) {
                val item = itemsArray.optJSONObject(i) ?: continue
                add(
                    ReceiptItem(
                        name = item.optString("name", ""),
                        qty = item.optDouble("qty", 0.0),
                        price = item.optDouble("price", 0.0),
                        subtotal = item.optDouble("subtotal", 0.0),
                    )
                )
            }
        }
        return ReceiptPayload(
            receipt_id = obj.optString("receipt_id", "-"),
            tanggal_jam = obj.optString("tanggal_jam", "-"),
            cashier = obj.optString("cashier", "Kasir"),
            store_name = obj.optString("store_name", "HOPe POS"),
            store_subtitle = obj.optString("store_subtitle", ""),
            store_address = obj.optString("store_address", ""),
            store_phone = obj.optString("store_phone", ""),
            footer = obj.optString("footer", ""),
            logo_url = obj.optString("logo_url", ""),
            payment_method = obj.optString("payment_method", "cash"),
            total = obj.optDouble("total", 0.0),
            bayar = obj.optDouble("bayar", 0.0),
            kembalian = obj.optDouble("kembalian", 0.0),
            items = items,
            paper_width = obj.optInt("paper_width", 58),
        )
    }

    private fun ReceiptPayload.toParsedReceipt(): id.my.hopenoodles.posbridge.print.ReceiptHtmlParser.ParsedReceipt {
        return id.my.hopenoodles.posbridge.print.ReceiptHtmlParser.ParsedReceipt(
            storeName = store_name,
            storeLines = listOfNotNull(store_subtitle, store_address, store_phone?.takeIf { it.isNotBlank() }?.let { "Telp: $it" })
                .filter { it.isNotBlank() },
            logoUrl = logo_url,
            receiptId = receipt_id,
            tanggal = tanggal_jam,
            cashier = cashier,
            items = items.map {
                id.my.hopenoodles.posbridge.print.ReceiptHtmlParser.Item(
                    name = it.name,
                    qtyPrice = "${it.qty.toInt()} x Rp %,.0f".format(it.price),
                    subtotal = "Rp %,.0f".format(it.subtotal)
                )
            },
            summary = listOf(
                "Total" to "Rp %,.0f".format(total),
                "Bayar" to "Rp %,.0f".format(bayar),
                "Kembalian" to "Rp %,.0f".format(kembalian),
                "Pembayaran" to payment_method.uppercase(),
            ),
            footer = footer,
        )
    }

    private fun fetchBitmap(url: String): Bitmap? {
        return runCatching {
            val req = Request.Builder().url(url).get().build()
            http.newCall(req).execute().use { res ->
                if (!res.isSuccessful) return@use null
                val bytes = res.body?.bytes() ?: return@use null
                BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
            }
        }.getOrNull()
    }

    private fun autoConnectLastPrinter() {
        val mac = prefs.printerMac() ?: return
        if (!btManager.isBluetoothEnabled()) return
        lifecycleScope.launch(Dispatchers.IO) {
            btManager.tryConnect(mac)
        }
    }

    private fun openPrinterSettings() {
        startActivity(Intent(this, PrinterSettingsActivity::class.java))
    }

    private fun ensureBluetoothPermissions() {
        val perms = mutableListOf<String>()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            perms += Manifest.permission.BLUETOOTH_CONNECT
            perms += Manifest.permission.BLUETOOTH_SCAN
        }
        val missing = perms.filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isNotEmpty()) permissionLauncher.launch(missing.toTypedArray())
    }

    private fun isTrustedUrl(url: String?): Boolean {
        if (url.isNullOrBlank()) return false
        val uri = Uri.parse(url)
        val host = uri.host ?: return false
        return host == trustedHost || host.endsWith(".$trustedHost")
    }

    private fun isOnPosRoot(url: String?): Boolean {
        return url != null && (url.startsWith(posUrl) || url == "https://$trustedHost/pos/" || url == "https://$trustedHost/pos")
    }

    private fun showExitConfirmation() {
        AlertDialog.Builder(this)
            .setTitle("Keluar aplikasi")
            .setMessage("Yakin ingin keluar dari HOPe POS?")
            .setNegativeButton("Batal", null)
            .setPositiveButton("Keluar") { _, _ -> finish() }
            .show()
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
    }
}
