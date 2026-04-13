package id.my.hopenoodles.posbridge.network

import id.my.hopenoodles.posbridge.data.PrintJobResponse
import id.my.hopenoodles.posbridge.data.PrintJobData
import id.my.hopenoodles.posbridge.data.ReceiptItem
import id.my.hopenoodles.posbridge.data.ReceiptPayload
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class PrintJobApi {
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.SECONDS)
        .build()

    fun getJob(baseUrl: String, token: String): PrintJobResponse {
        val req = Request.Builder()
            .url("${baseUrl.trimEnd('/')}/pos/print_job_api.php?action=get&token=$token")
            .get()
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string().orEmpty()
            if (!res.isSuccessful) {
                return PrintJobResponse(ok = false, message = "HTTP ${res.code}: ${body.take(120)}")
            }
            return parseResponse(body)
        }
    }

    fun markPrinted(baseUrl: String, token: String): PrintJobResponse {
        val req = Request.Builder()
            .url("${baseUrl.trimEnd('/')}/pos/print_job_api.php?action=mark_printed&token=$token")
            .get()
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string().orEmpty()
            if (!res.isSuccessful) {
                return PrintJobResponse(ok = false, message = "Mark printed gagal (HTTP ${res.code})")
            }
            return parseResponse(body)
        }
    }

    private fun parseResponse(json: String): PrintJobResponse {
        val obj = JSONObject(json)
        val ok = obj.optBoolean("ok", false)
        val message = obj.optString("message", null)
        if (!ok) return PrintJobResponse(false, message)

        val dataObj = obj.optJSONObject("data") ?: return PrintJobResponse(false, "Data kosong")
        val payloadObj = dataObj.optJSONObject("payload") ?: return PrintJobResponse(false, "Payload kosong")
        val itemsArray = payloadObj.optJSONArray("items") ?: JSONArray()
        val items = buildList {
            for (i in 0 until itemsArray.length()) {
                val item = itemsArray.optJSONObject(i) ?: continue
                add(
                    ReceiptItem(
                        name = item.optString("name", "-"),
                        qty = item.optDouble("qty", 0.0),
                        price = item.optDouble("price", 0.0),
                        subtotal = item.optDouble("subtotal", 0.0),
                    )
                )
            }
        }

        val payload = ReceiptPayload(
            receipt_id = payloadObj.optString("receipt_id", "-"),
            tanggal_jam = payloadObj.optString("tanggal_jam", "-"),
            cashier = payloadObj.optString("cashier", "-"),
            store_name = payloadObj.optString("store_name", "HOPe"),
            store_subtitle = payloadObj.optString("store_subtitle", ""),
            store_address = payloadObj.optString("store_address", ""),
            store_phone = payloadObj.optString("store_phone", ""),
            footer = payloadObj.optString("footer", ""),
            logo_url = payloadObj.optString("logo_url", ""),
            payment_method = payloadObj.optString("payment_method", "cash"),
            total = payloadObj.optDouble("total", 0.0),
            bayar = payloadObj.optDouble("bayar", 0.0),
            kembalian = payloadObj.optDouble("kembalian", 0.0),
            items = items,
            paper_width = payloadObj.optInt("paper_width", 58),
        )

        return PrintJobResponse(
            ok = true,
            data = PrintJobData(
                token = dataObj.optString("token", ""),
                status = dataObj.optString("status", "pending"),
                expires_at = dataObj.optString("expires_at", ""),
                payload = payload,
            )
        )
    }
}
