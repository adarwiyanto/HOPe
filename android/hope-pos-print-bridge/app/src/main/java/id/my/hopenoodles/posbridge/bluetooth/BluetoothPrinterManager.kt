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
    private val spp: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

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

    fun tryConnect(macAddress: String): Boolean {
        return runCatching {
            connectSocket(macAddress).use { socket -> socket.isConnected }
        }.getOrDefault(false)
    }

    fun print(macAddress: String, bytes: ByteArray) {
        val device = remoteDevice(macAddress)
        var socket: BluetoothSocket? = null
        var out: OutputStream? = null
        try {
            socket = createConnectedSocket(device)
            out = socket.outputStream
            out.write(bytes)
            out.flush()
        } finally {
            runCatching { out?.close() }
            runCatching { socket?.close() }
        }
    }

    private fun connectSocket(macAddress: String): BluetoothSocket {
        val device = remoteDevice(macAddress)
        return createConnectedSocket(device)
    }

    private fun remoteDevice(macAddress: String): BluetoothDevice {
        if (!hasPermissions()) throw IllegalStateException("Permission Bluetooth belum diberikan")
        if (!isBluetoothEnabled()) throw IllegalStateException("Bluetooth belum aktif")
        return adapter?.getRemoteDevice(macAddress) ?: throw IllegalStateException("Printer tidak ditemukan")
    }

    private fun createConnectedSocket(device: BluetoothDevice): BluetoothSocket {
        adapter?.cancelDiscovery()
        val socket = device.createRfcommSocketToServiceRecord(spp)
        socket.connect()
        if (!socket.isConnected) {
            socket.close()
            throw IllegalStateException("Gagal terhubung ke printer")
        }
        return socket
    }
}
