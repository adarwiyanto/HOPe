package id.my.hopenoodles.posbridge.bluetooth

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import java.io.OutputStream
import java.util.UUID

class BluetoothPrinterManager(private val context: Context) {
    private val adapter: BluetoothAdapter? = BluetoothAdapter.getDefaultAdapter()

    fun hasPermissions(): Boolean {
        return if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        } else true
    }

    fun pairedDevices(): List<BluetoothDevice> {
        if (!hasPermissions()) return emptyList()
        val bonded = adapter?.bondedDevices ?: emptySet()
        return bonded.sortedBy { it.name ?: "" }
    }

    fun isBluetoothEnabled(): Boolean = adapter?.isEnabled == true

    fun print(macAddress: String, bytes: ByteArray) {
        if (!hasPermissions()) throw IllegalStateException("Permission Bluetooth belum diberikan")
        val device = adapter?.getRemoteDevice(macAddress) ?: throw IllegalStateException("Printer tidak ditemukan")
        val spp = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
        var socket: BluetoothSocket? = null
        var out: OutputStream? = null
        try {
            socket = device.createRfcommSocketToServiceRecord(spp)
            socket.connect()
            out = socket.outputStream
            out.write(bytes)
            out.flush()
        } finally {
            kotlin.runCatching { out?.close() }
            kotlin.runCatching { socket?.close() }
        }
    }
}
