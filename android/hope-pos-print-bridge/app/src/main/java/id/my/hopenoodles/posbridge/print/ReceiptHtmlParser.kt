package id.my.hopenoodles.posbridge.print

import org.json.JSONObject
import org.jsoup.Jsoup

object ReceiptHtmlParser {
    data class ParsedReceipt(
        val storeName: String,
        val storeLines: List<String>,
        val logoUrl: String?,
        val receiptId: String,
        val tanggal: String,
        val cashier: String,
        val items: List<Item>,
        val summary: List<Pair<String, String>>,
        val footer: String?,
    )

    data class Item(
        val name: String,
        val qtyPrice: String,
        val subtotal: String,
    )

    fun parse(receiptHtml: String, metaJson: String?): ParsedReceipt {
        val doc = Jsoup.parse(receiptHtml)
        val root = doc.selectFirst("#receipt-print-root") ?: doc
        val meta = metaJson?.takeIf { it.isNotBlank() }?.let { runCatching { JSONObject(it) }.getOrNull() }

        val storeName = root.selectFirst(".receipt-store-name")?.text()?.trim()
            ?: root.attr("data-store-name").trim()
            ?: meta?.optString("storeName").orEmpty()
        val storeLines = root.select(".receipt-store-line").map { it.text().trim() }.filter { it.isNotBlank() }
        val logoUrl = root.selectFirst(".receipt-logo img")?.absUrl("src")?.ifBlank { null }
            ?: root.attr("data-logo-src").trim().ifBlank { null }
            ?: meta?.optString("logoUrl")?.ifBlank { null }

        val metaRows = root.select(".receipt-meta > div").map { it.text().trim() }
        val receiptId = metaRows.firstOrNull { it.startsWith("No:") }?.substringAfter("No:")?.trim()
            ?: root.attr("data-receipt-id").trim()
            ?: meta?.optString("receiptId").orEmpty()
        val tanggal = metaRows.firstOrNull { it.startsWith("Tanggal:") }?.substringAfter("Tanggal:")?.trim()
            ?: root.attr("data-time").trim()
            ?: meta?.optString("time").orEmpty()
        val cashier = metaRows.firstOrNull { it.startsWith("Kasir:") }?.substringAfter("Kasir:")?.trim()
            ?: root.attr("data-cashier").trim()
            ?: meta?.optString("cashier").orEmpty()

        val items = root.select(".receipt-item").map {
            val name = it.selectFirst(".receipt-item-name")?.text()?.trim().orEmpty()
            val qtyPrice = it.selectFirst(".receipt-item-qty")?.text()?.trim().orEmpty()
            val subtotal = it.selectFirst(".receipt-item-subtotal")?.text()?.trim().orEmpty()
            Item(name = name, qtyPrice = qtyPrice, subtotal = subtotal)
        }.filter { it.name.isNotBlank() }

        val summary = root.select(".receipt-summary .receipt-line").mapNotNull {
            val cols = it.select("span")
            if (cols.size >= 2) cols[0].text().trim() to cols[1].text().trim() else null
        }

        val footer = root.selectFirst(".receipt-footer")?.text()?.trim()?.ifBlank { null }

        return ParsedReceipt(
            storeName = storeName.ifBlank { "HOPe POS" },
            storeLines = storeLines,
            logoUrl = logoUrl,
            receiptId = receiptId,
            tanggal = tanggal,
            cashier = cashier,
            items = items,
            summary = summary,
            footer = footer,
        )
    }
}
