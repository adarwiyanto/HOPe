package id.my.hopenoodles.posbridge.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import id.my.hopenoodles.posbridge.bluetooth.BluetoothPrinterManager
import id.my.hopenoodles.posbridge.bluetooth.EscPosFormatter
import id.my.hopenoodles.posbridge.data.PrinterPrefs
import id.my.hopenoodles.posbridge.data.ReceiptPayload
import id.my.hopenoodles.posbridge.databinding.ActivityReceiptBinding
import id.my.hopenoodles.posbridge.network.PrintJobApi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class ReceiptActivity : AppCompatActivity() {
    private lateinit var binding: ActivityReceiptBinding
    private val api = PrintJobApi()
    private lateinit var prefs: PrinterPrefs
    private lateinit var btManager: BluetoothPrinterManager

    private var token: String = ""
    private var baseUrl: String = ""
    private var payload: ReceiptPayload? = null

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { perms ->
        val ok = perms.values.all { it }
        if (!ok) {
            toast("Permission Bluetooth dibutuhkan untuk print")
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityReceiptBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = PrinterPrefs(this)
        btManager = BluetoothPrinterManager(this)

        val deep = intent?.data
        if (!parseDeepLink(deep)) {
            toast("Deep link tidak valid")
            finish()
            return
        }

        binding.btnPickPrinter.setOnClickListener {
            startActivity(Intent(this, PrinterSettingsActivity::class.java))
        }
        binding.btnRetry.setOnClickListener { fetchJob() }
        binding.btnPrint.setOnClickListener { printReceipt() }

        ensureBluetoothPermissions()
        fetchJob()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (parseDeepLink(intent.data)) fetchJob()
    }

    private fun parseDeepLink(uri: Uri?): Boolean {
        if (uri == null) return false
        if (uri.scheme != "hopepos" || uri.host != "print") return false
        token = uri.getQueryParameter("token").orEmpty()
        baseUrl = uri.getQueryParameter("base").orEmpty()
        return token.isNotBlank() && baseUrl.startsWith("https://")
    }

    private fun ensureBluetoothPermissions() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return
        val perms = arrayOf(Manifest.permission.BLUETOOTH_CONNECT, Manifest.permission.BLUETOOTH_SCAN)
        val missing = perms.filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isNotEmpty()) permissionLauncher.launch(missing.toTypedArray())
    }

    private fun fetchJob() {
        binding.txtStatus.text = "Mengambil payload print job..."
        lifecycleScope.launch {
            val response = withContext(Dispatchers.IO) { api.getJob(baseUrl, token) }
            if (!response.ok || response.data == null) {
                binding.txtStatus.text = response.message ?: "Gagal mengambil data"
                return@launch
            }
            payload = response.data.payload
            bindPreview(response.data.payload)
            binding.txtStatus.text = "Print job siap. Klik Cetak."
        }
    }

    private fun bindPreview(r: ReceiptPayload) {
        val lines = StringBuilder().apply {
            appendLine(r.store_name)
            if (!r.store_subtitle.isNullOrBlank()) appendLine(r.store_subtitle)
            if (!r.store_address.isNullOrBlank()) appendLine(r.store_address)
            appendLine("------------------------------")
            appendLine("No: ${r.receipt_id}")
            appendLine("Tanggal: ${r.tanggal_jam}")
            appendLine("Kasir: ${r.cashier}")
            appendLine("------------------------------")
            r.items.forEach {
                appendLine(it.name)
                appendLine("${it.qty.toInt()} x Rp ${"%,.0f".format(it.price)}    Rp ${"%,.0f".format(it.subtotal)}")
            }
            appendLine("------------------------------")
            appendLine("TOTAL      Rp ${"%,.0f".format(r.total)}")
            appendLine("BAYAR      Rp ${"%,.0f".format(r.bayar)}")
            appendLine("KEMBALIAN  Rp ${"%,.0f".format(r.kembalian)}")
            appendLine("PAY: ${r.payment_method.uppercase()}")
            if (!r.footer.isNullOrBlank()) appendLine(r.footer)
        }
        binding.txtPreview.text = lines.toString()
    }

    private fun printReceipt() {
        val data = payload ?: return
        val mac = prefs.printerMac()
        if (mac.isNullOrBlank()) {
            toast("Pilih printer dulu")
            startActivity(Intent(this, PrinterSettingsActivity::class.java))
            return
        }
        if (!btManager.isBluetoothEnabled()) {
            toast("Bluetooth mati")
            return
        }

        binding.btnPrint.isEnabled = false
        binding.txtStatus.text = "Mencetak..."
        lifecycleScope.launch {
            val printError = withContext(Dispatchers.IO) {
                kotlin.runCatching {
                    btManager.print(mac, EscPosFormatter.format(data))
                }.exceptionOrNull()?.message
            }

            if (printError != null) {
                binding.txtStatus.text = "Print gagal: $printError"
                binding.btnPrint.isEnabled = true
                return@launch
            }

            val marked = withContext(Dispatchers.IO) { api.markPrinted(baseUrl, token) }
            if (!marked.ok) {
                binding.txtStatus.text = "Sudah tercetak, tapi mark_printed gagal: ${marked.message}"
            } else {
                binding.txtStatus.text = "Print berhasil"
                toast("Print berhasil")
            }
            binding.btnPrint.isEnabled = true
        }
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
}
