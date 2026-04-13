package id.my.hopenoodles.hopepos.bluetooth

import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.io.IOException
import java.util.UUID

class BluetoothPrinterManager(context: Context) {
    private val adapter: BluetoothAdapter? = BluetoothAdapter.getDefaultAdapter()
    private var activeSocket: BluetoothSocket? = null
    private val ioMutex = Mutex()

    fun isBluetoothEnabled(): Boolean = adapter?.isEnabled == true

    @SuppressLint("MissingPermission")
    fun getBondedDevices(): List<BluetoothDevice> {
        val bonded = adapter?.bondedDevices ?: return emptyList()
        return bonded.sortedBy { it.name ?: it.address }
    }

    suspend fun autoConnect(mac: String): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching { connect(mac) }
    }

    suspend fun reconnect(mac: String): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching {
            disconnect()
            connect(mac)
        }
    }

    suspend fun print(mac: String, bytes: ByteArray) = withContext(Dispatchers.IO) {
        ioMutex.withLock {
            val socket = ensureConnected(mac)
            val out = socket.outputStream
            out.write(bytes)
            out.flush()
        }
    }

    @SuppressLint("MissingPermission")
    private fun ensureConnected(mac: String): BluetoothSocket {
        val current = activeSocket
        if (current != null && current.isConnected) return current
        connect(mac)
        return activeSocket ?: throw IOException("Socket tidak tersedia")
    }

    @SuppressLint("MissingPermission")
    private fun connect(mac: String) {
        val btAdapter = adapter ?: throw IOException("Bluetooth tidak tersedia")
        if (!btAdapter.isEnabled) throw IOException("Bluetooth mati")

        btAdapter.cancelDiscovery()
        val device = btAdapter.getRemoteDevice(mac)

        val attempts = listOf<(BluetoothDevice) -> BluetoothSocket>(
            { it.createRfcommSocketToServiceRecord(SPP_UUID) },
            { it.createInsecureRfcommSocketToServiceRecord(SPP_UUID) },
            { dev ->
                val method = dev.javaClass.getMethod("createRfcommSocket", Int::class.javaPrimitiveType)
                method.invoke(dev, 1) as BluetoothSocket
            },
        )

        var lastError: Throwable? = null
        for (builder in attempts) {
            val socket = runCatching { builder(device) }.getOrElse {
                lastError = it
                null
            } ?: continue

            runCatching {
                socket.connect()
                activeSocket = socket
                return
            }.onFailure {
                lastError = it
                runCatching { socket.close() }
            }
        }

        throw IOException("Gagal konek ke printer: ${lastError?.message ?: "unknown"}")
    }

    fun disconnect() {
        runCatching { activeSocket?.close() }
        activeSocket = null
    }

    companion object {
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
    }
}
