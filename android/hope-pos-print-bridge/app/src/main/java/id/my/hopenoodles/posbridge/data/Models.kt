package id.my.hopenoodles.posbridge.data

data class PrintJobResponse(
    val ok: Boolean,
    val message: String? = null,
    val data: PrintJobData? = null,
)

data class PrintJobData(
    val token: String,
    val status: String,
    val expires_at: String,
    val payload: ReceiptPayload,
)

data class ReceiptPayload(
    val receipt_id: String,
    val tanggal_jam: String,
    val cashier: String,
    val store_name: String,
    val store_subtitle: String? = null,
    val store_address: String? = null,
    val store_phone: String? = null,
    val footer: String? = null,
    val payment_method: String,
    val total: Double,
    val bayar: Double,
    val kembalian: Double,
    val items: List<ReceiptItem>,
    val paper_width: Int = 58,
)

data class ReceiptItem(
    val name: String,
    val qty: Double,
    val price: Double,
    val subtotal: Double,
)
