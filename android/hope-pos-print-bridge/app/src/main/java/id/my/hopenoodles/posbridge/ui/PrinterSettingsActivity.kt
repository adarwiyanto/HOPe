package id.my.hopenoodles.posbridge.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import id.my.hopenoodles.posbridge.bluetooth.BluetoothPrinterManager
import id.my.hopenoodles.posbridge.data.PrinterPrefs
import id.my.hopenoodles.posbridge.databinding.ActivityPrinterSettingsBinding

class PrinterSettingsActivity : AppCompatActivity() {
    private lateinit var binding: ActivityPrinterSettingsBinding
    private lateinit var prefs: PrinterPrefs
    private lateinit var manager: BluetoothPrinterManager

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        loadDevices()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPrinterSettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = PrinterPrefs(this)
        manager = BluetoothPrinterManager(this)

        binding.btnReload.setOnClickListener { ensurePermissionsAndLoad() }
        ensurePermissionsAndLoad()
    }

    private fun ensurePermissionsAndLoad() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val perms = arrayOf(Manifest.permission.BLUETOOTH_CONNECT, Manifest.permission.BLUETOOTH_SCAN)
            val missing = perms.filter {
                ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
            }
            if (missing.isNotEmpty()) {
                permissionLauncher.launch(missing.toTypedArray())
                return
            }
        }
        loadDevices()
    }

    private fun loadDevices() {
        if (!manager.isBluetoothEnabled()) {
            Toast.makeText(this, "Bluetooth belum aktif", Toast.LENGTH_SHORT).show()
        }
        val devices = manager.pairedDevices()
        val labels = devices.map { "${it.name ?: "Unknown"} (${it.address})" }
        binding.deviceList.adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, labels)
        binding.deviceList.setOnItemClickListener { _, _, position, _ ->
            val d = devices[position]
            prefs.savePrinter(d.name ?: "Unknown", d.address)
            Toast.makeText(this, "Printer dipilih: ${d.address}", Toast.LENGTH_SHORT).show()
            finish()
        }
        val current = prefs.printerMac()?.let { mac -> "Printer saat ini: ${prefs.printerName()} ($mac)" } ?: "Belum ada printer dipilih"
        binding.txtCurrent.text = current
    }
}
