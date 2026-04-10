package id.my.hopenoodles.posbridge.bluetooth

import id.my.hopenoodles.posbridge.data.ReceiptPayload
import java.nio.charset.Charset

object EscPosFormatter {
    private const val WIDTH = 32
    private val charset = Charset.forName("CP437")

    fun format(payload: ReceiptPayload): ByteArray {
        val sb = StringBuilder()
        sb.append(escInit())
        sb.append(alignCenter())
        sb.append(boldOn())
        sb.appendln(center(payload.store_name.uppercase()))
        sb.append(boldOff())
        payload.store_subtitle?.takeIf { it.isNotBlank() }?.let { sb.appendln(center(it)) }
        payload.store_address?.takeIf { it.isNotBlank() }?.let { sb.appendln(center(it)) }
        payload.store_phone?.takeIf { it.isNotBlank() }?.let { sb.appendln(center("Telp: $it")) }
        sb.appendln("-".repeat(WIDTH))

        sb.append(alignLeft())
        sb.appendln("No: ${payload.receipt_id}")
        sb.appendln("Tgl: ${payload.tanggal_jam}")
        sb.appendln("Kasir: ${payload.cashier}")
        sb.appendln("-".repeat(WIDTH))

        payload.items.forEach { item ->
            sb.appendln(cut(item.name, WIDTH))
            val left = "${item.qty.toInt()} x ${idr(item.price)}"
            val right = idr(item.subtotal)
            sb.appendln(twoCol(left, right))
        }

        sb.appendln("-".repeat(WIDTH))
        sb.appendln(twoCol("TOTAL", idr(payload.total)))
        sb.appendln(twoCol("BAYAR", idr(payload.bayar)))
        sb.appendln(twoCol("KEMBALIAN", idr(payload.kembalian)))
        sb.appendln(twoCol("PEMBAYARAN", payload.payment_method.uppercase()))
        payload.footer?.takeIf { it.isNotBlank() }?.let {
            sb.appendln("-".repeat(WIDTH))
            sb.append(alignCenter())
            sb.appendln(center(it))
            sb.append(alignLeft())
        }

        sb.append(feed(4))
        sb.append(cut())
        return sb.toString().toByteArray(charset)
    }

    private fun idr(value: Double): String = "Rp %,.0f".format(value)
    private fun twoCol(left: String, right: String): String {
        val room = WIDTH - right.length
        val l = if (left.length > room) left.take(room) else left.padEnd(room, ' ')
        return l + right
    }
    private fun cut(s: String, width: Int): String = if (s.length <= width) s else s.take(width)
    private fun center(s: String): String {
        if (s.length >= WIDTH) return s.take(WIDTH)
        val p = (WIDTH - s.length) / 2
        return " ".repeat(p) + s
    }

    private fun escInit(): String = String(byteArrayOf(0x1B, 0x40))
    private fun boldOn(): String = String(byteArrayOf(0x1B, 0x45, 0x01))
    private fun boldOff(): String = String(byteArrayOf(0x1B, 0x45, 0x00))
    private fun alignLeft(): String = String(byteArrayOf(0x1B, 0x61, 0x00))
    private fun alignCenter(): String = String(byteArrayOf(0x1B, 0x61, 0x01))
    private fun feed(lines: Int): String = String(ByteArray(lines) { 0x0A })
    private fun cut(): String = String(byteArrayOf(0x1D, 0x56, 0x00))
}
