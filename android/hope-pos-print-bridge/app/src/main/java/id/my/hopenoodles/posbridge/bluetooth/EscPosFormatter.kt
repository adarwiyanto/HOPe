package id.my.hopenoodles.posbridge.bluetooth

import android.graphics.Bitmap
import id.my.hopenoodles.posbridge.data.ReceiptPayload
import id.my.hopenoodles.posbridge.print.ReceiptHtmlParser
import java.io.ByteArrayOutputStream
import kotlin.math.min

object EscPosFormatter {
    private const val WIDTH = 32


    fun format(payload: ReceiptPayload): ByteArray {
        val parsed = ReceiptHtmlParser.ParsedReceipt(
            storeName = payload.store_name,
            storeLines = listOfNotNull(payload.store_subtitle, payload.store_address, payload.store_phone?.let { "Telp: $it" }),
            logoUrl = null,
            receiptId = payload.receipt_id,
            tanggal = payload.tanggal_jam,
            cashier = payload.cashier,
            items = payload.items.map {
                ReceiptHtmlParser.Item(
                    name = it.name,
                    qtyPrice = "${it.qty.toInt()} x Rp %,.0f".format(it.price),
                    subtotal = "Rp %,.0f".format(it.subtotal)
                )
            },
            summary = listOf(
                "Total" to "Rp %,.0f".format(payload.total),
                "Bayar" to "Rp %,.0f".format(payload.bayar),
                "Kembalian" to "Rp %,.0f".format(payload.kembalian),
                "Pembayaran" to payload.payment_method.uppercase(),
            ),
            footer = payload.footer,
        )
        return formatFromParsed(parsed, null)
    }

    fun formatFromParsed(receipt: ReceiptHtmlParser.ParsedReceipt, logo: Bitmap? = null): ByteArray {
        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(0x1B, 0x40))

        if (logo != null) {
            out.write(alignCenter())
            out.write(bitmapToEscPos(logo, 384))
            out.write(lineFeed(1))
        }

        out.write(alignCenter())
        out.write(boldOn())
        out.write((center(receipt.storeName.uppercase()) + "\n").toByteArray())
        out.write(boldOff())
        receipt.storeLines.forEach { out.write((center(it) + "\n").toByteArray()) }
        out.write(("-".repeat(WIDTH) + "\n").toByteArray())

        out.write(alignLeft())
        if (receipt.receiptId.isNotBlank()) out.write(("No: ${receipt.receiptId}\n").toByteArray())
        if (receipt.tanggal.isNotBlank()) out.write(("Tgl: ${receipt.tanggal}\n").toByteArray())
        if (receipt.cashier.isNotBlank()) out.write(("Kasir: ${receipt.cashier}\n").toByteArray())
        out.write(("-".repeat(WIDTH) + "\n").toByteArray())

        receipt.items.forEach { item ->
            wrap(item.name, WIDTH).forEach { out.write((it + "\n").toByteArray()) }
            out.write((twoCol(item.qtyPrice, item.subtotal) + "\n").toByteArray())
        }

        out.write(("-".repeat(WIDTH) + "\n").toByteArray())
        receipt.summary.forEach { (label, value) -> out.write((twoCol(label.uppercase(), value) + "\n").toByteArray()) }

        receipt.footer?.takeIf { it.isNotBlank() }?.let {
            out.write(("-".repeat(WIDTH) + "\n").toByteArray())
            out.write(alignCenter())
            wrap(it, WIDTH).forEach { line -> out.write((center(line) + "\n").toByteArray()) }
            out.write(alignLeft())
        }

        out.write(lineFeed(4))
        out.write(byteArrayOf(0x1D, 0x56, 0x00))
        return out.toByteArray()
    }

    private fun twoCol(left: String, right: String): String {
        val rightText = right.take(WIDTH)
        val room = (WIDTH - rightText.length).coerceAtLeast(1)
        val leftText = if (left.length > room) left.take(room) else left.padEnd(room, ' ')
        return leftText + rightText
    }

    private fun center(s: String): String {
        if (s.length >= WIDTH) return s.take(WIDTH)
        val p = (WIDTH - s.length) / 2
        return " ".repeat(p) + s
    }

    private fun wrap(text: String, width: Int): List<String> {
        val clean = text.trim()
        if (clean.length <= width) return listOf(clean)
        val lines = mutableListOf<String>()
        var start = 0
        while (start < clean.length) {
            val end = min(start + width, clean.length)
            lines += clean.substring(start, end)
            start = end
        }
        return lines
    }

    private fun alignLeft() = byteArrayOf(0x1B, 0x61, 0x00)
    private fun alignCenter() = byteArrayOf(0x1B, 0x61, 0x01)
    private fun boldOn() = byteArrayOf(0x1B, 0x45, 0x01)
    private fun boldOff() = byteArrayOf(0x1B, 0x45, 0x00)
    private fun lineFeed(lines: Int): ByteArray = ByteArray(lines) { 0x0A }

    private fun bitmapToEscPos(bitmap: Bitmap, maxWidth: Int): ByteArray {
        val width = min(bitmap.width, maxWidth)
        val scale = width.toFloat() / bitmap.width.toFloat()
        val height = (bitmap.height * scale).toInt().coerceAtLeast(1)
        val scaled = Bitmap.createScaledBitmap(bitmap, width, height, true)
        val bytesPerRow = (scaled.width + 7) / 8
        val imageBytes = ByteArray(bytesPerRow * scaled.height)

        for (y in 0 until scaled.height) {
            for (x in 0 until scaled.width) {
                val color = scaled.getPixel(x, y)
                val r = (color shr 16) and 0xFF
                val g = (color shr 8) and 0xFF
                val b = color and 0xFF
                val gray = (r * 0.3 + g * 0.59 + b * 0.11).toInt()
                val isBlack = gray < 150
                if (isBlack) {
                    val index = y * bytesPerRow + (x / 8)
                    imageBytes[index] = (imageBytes[index].toInt() or (0x80 shr (x % 8))).toByte()
                }
            }
        }

        val out = ByteArrayOutputStream()
        out.write(byteArrayOf(0x1D, 0x76, 0x30, 0x00, (bytesPerRow and 0xFF).toByte(), ((bytesPerRow shr 8) and 0xFF).toByte(), (scaled.height and 0xFF).toByte(), ((scaled.height shr 8) and 0xFF).toByte()))
        out.write(imageBytes)
        return out.toByteArray()
    }
}
