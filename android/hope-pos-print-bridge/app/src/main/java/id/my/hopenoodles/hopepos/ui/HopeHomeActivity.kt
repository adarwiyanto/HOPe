package id.my.hopenoodles.hopepos.ui

import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.GridLayout
import android.widget.ImageButton
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import id.my.hopenoodles.hopepos.R
import id.my.hopenoodles.hopepos.kiosk.KioskManager
import id.my.hopenoodles.hopepos.kiosk.KioskPrefs

class HopeHomeActivity : AppCompatActivity() {
    private lateinit var prefs: KioskPrefs
    private lateinit var kioskManager: KioskManager
    private lateinit var appGrid: GridLayout
    private lateinit var rootFrame: FrameLayout
    private lateinit var backgroundImage: ImageView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = KioskPrefs(this)
        kioskManager = KioskManager(this)

        if (!prefs.isSetupComplete()) {
            startActivity(Intent(this, KioskSetupActivity::class.java))
            finish()
            return
        }

        buildUi()
        showLauncherSplash()
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                Toast.makeText(this@HopeHomeActivity, "HOPE Launcher aktif.", Toast.LENGTH_SHORT).show()
            }
        })
    }

    override fun onResume() {
        super.onResume()
        if (::backgroundImage.isInitialized) applyLauncherBackground()
        if (::appGrid.isInitialized) refreshAllowedApps()
        applyKioskIfNeeded()
    }

    private fun applyKioskIfNeeded() {
        if (!prefs.isKioskEnabled()) return
        if (prefs.isAdminUnlocked()) {
            kioskManager.stopKiosk(this)
            return
        }
        kioskManager.startKiosk(this, prefs.getAllowedPackages())
    }

    private fun buildUi() {
        rootFrame = FrameLayout(this).apply {
            background = defaultBackgroundDrawable()
        }

        backgroundImage = ImageView(this).apply {
            scaleType = ImageView.ScaleType.CENTER_CROP
            alpha = 0.35f
            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            )
        }
        rootFrame.addView(backgroundImage)
        applyLauncherBackground()

        val root = ScrollView(this).apply {
            isFillViewport = true
            setPadding(dp(20), dp(18), dp(20), dp(18))
        }
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            setPadding(dp(12), dp(10), dp(12), dp(18))
            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            )
        }
        root.addView(content)
        rootFrame.addView(root)

        rootFrame.addView(ImageButton(this).apply {
            setImageResource(android.R.drawable.ic_menu_manage)
            background = roundedDrawable(Color.argb(95, 255, 255, 255), dp(24))
            setColorFilter(Color.WHITE)
            contentDescription = "Admin"
            setPadding(dp(10), dp(10), dp(10), dp(10))
            layoutParams = FrameLayout.LayoutParams(dp(52), dp(52), Gravity.TOP or Gravity.END).apply {
                setMargins(0, dp(12), dp(12), 0)
            }
            setOnClickListener {
                requireAdminPin("Pengaturan Launcher HOPe") {
                    startActivity(Intent(this@HopeHomeActivity, HopeLauncherSettingsActivity::class.java))
                }
            }
        })

        content.addView(ImageView(this).apply {
            setImageResource(R.drawable.hope_launcher_splash)
            adjustViewBounds = true
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = LinearLayout.LayoutParams(dp(210), dp(210)).apply {
                setMargins(0, dp(8), 0, dp(8))
            }
        })

        content.addView(TextView(this).apply {
            text = "HOPe Launcher"
            textSize = 28f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
        })
        content.addView(TextView(this).apply {
            text = if (kioskManager.isHopeDefaultHome()) {
                "Home default aktif"
            } else {
                "HOPe belum menjadi Home default"
            }
            textSize = 14f
            setTextColor(Color.argb(220, 255, 255, 255))
            gravity = Gravity.CENTER
            setPadding(0, dp(4), 0, dp(20))
        })

        appGrid = GridLayout(this).apply {
            columnCount = calculateColumnCount()
            useDefaultMargins = false
            alignmentMode = GridLayout.ALIGN_BOUNDS
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            )
            setPadding(0, dp(8), 0, dp(12))
        }
        content.addView(appGrid)
        refreshAllowedApps()

        setContentView(rootFrame)
    }

    private fun refreshAllowedApps() {
        appGrid.removeAllViews()
        appGrid.columnCount = calculateColumnCount()

        appGrid.addView(appTile("HOPe POS", getDrawableCompat(R.drawable.hope_launcher_icon)) {
            startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP))
        })

        val allowedPackages = prefs.getAllowedPackages()
        allowedPackages.forEach { packageName ->
            appGrid.addView(appTile(getAppLabel(packageName), getAppIcon(packageName)) { launchAllowedPackage(packageName) })
        }

        if (allowedPackages.isEmpty()) {
            appGrid.addView(infoTile("Aplikasi tambahan belum ada. Tambahkan lewat ikon admin."))
        }
    }

    private fun launchAllowedPackage(packageName: String) {
        val launchIntent = packageManager.getLaunchIntentForPackage(packageName)
        if (launchIntent == null) {
            Toast.makeText(this, "Aplikasi tidak ditemukan: $packageName", Toast.LENGTH_LONG).show()
            return
        }
        runCatching { startActivity(launchIntent) }
            .onFailure { Toast.makeText(this, "Aplikasi gagal dibuka.", Toast.LENGTH_LONG).show() }
    }

    private fun openDeviceSettingsMenu() {
        val items = arrayOf("Wi-Fi", "Bluetooth", "Info Aplikasi HOPe", "Default Apps / Home", "Full Settings")
        AlertDialog.Builder(this)
            .setTitle("Settings Device")
            .setItems(items) { dialog, which ->
                val intent = when (which) {
                    0 -> Intent(Settings.ACTION_WIFI_SETTINGS)
                    1 -> Intent(Settings.ACTION_BLUETOOTH_SETTINGS)
                    2 -> Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                        data = Uri.parse("package:$packageName")
                    }
                    3 -> Intent(Settings.ACTION_HOME_SETTINGS)
                    else -> Intent(Settings.ACTION_SETTINGS)
                }
                runCatching { startActivity(intent) }
                    .onFailure { startActivity(Intent(Settings.ACTION_SETTINGS)) }
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun requireAdminPin(title: String, onSuccess: () -> Unit) {
        val input = EditText(this).apply {
            hint = "PIN admin"
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            setPadding(dp(18), dp(8), dp(18), dp(8))
        }
        AlertDialog.Builder(this)
            .setTitle(title)
            .setMessage("Masukkan PIN admin untuk melanjutkan.")
            .setView(input)
            .setPositiveButton("Buka") { dialog, _ ->
                val pin = input.text?.toString().orEmpty()
                if (prefs.verifyPin(pin)) {
                    onSuccess()
                } else {
                    Toast.makeText(this, "PIN salah.", Toast.LENGTH_LONG).show()
                }
                dialog.dismiss()
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun showLauncherSplash() {
        val splash = FrameLayout(this).apply {
            setBackgroundColor(Color.rgb(226, 47, 31))
            alpha = 1f
            isClickable = true
            elevation = dp(12).toFloat()
        }
        splash.addView(ImageView(this).apply {
            setImageResource(R.drawable.hope_launcher_splash)
            adjustViewBounds = true
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = FrameLayout.LayoutParams(dp(360), dp(360), Gravity.CENTER)
        })
        rootFrame.addView(splash, FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT,
        ))
        splash.postDelayed({
            splash.animate()
                .alpha(0f)
                .setDuration(350)
                .withEndAction { rootFrame.removeView(splash) }
                .start()
        }, 900)
    }

    private fun applyLauncherBackground() {
        val customUri = prefs.getLauncherBackgroundUri()
        if (customUri.isNullOrBlank()) {
            backgroundImage.visibility = View.GONE
            return
        }
        runCatching {
            backgroundImage.setImageURI(Uri.parse(customUri))
            backgroundImage.visibility = View.VISIBLE
        }.onFailure {
            backgroundImage.visibility = View.GONE
        }
    }

    private fun getAppLabel(packageName: String): String {
        return runCatching {
            val info = packageManager.getApplicationInfo(packageName, 0)
            packageManager.getApplicationLabel(info).toString()
        }.getOrDefault(packageName)
    }

    private fun getAppIcon(packageName: String): Drawable? {
        return runCatching {
            val info = packageManager.getApplicationInfo(packageName, 0)
            packageManager.getApplicationIcon(info)
        }.getOrNull()
    }

    private fun getDrawableCompat(drawableId: Int): Drawable? = runCatching { getDrawable(drawableId) }.getOrNull()

    private fun appTile(textValue: String, icon: Drawable?, action: () -> Unit): LinearLayout = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.CENTER
        background = roundedDrawable(Color.argb(120, 255, 255, 255), dp(22))
        isClickable = true
        isFocusable = true
        setPadding(dp(10), dp(14), dp(10), dp(12))
        layoutParams = GridLayout.LayoutParams().apply {
            width = tileWidth()
            height = dp(142)
            setMargins(dp(8), dp(8), dp(8), dp(8))
        }
        setOnClickListener { action() }

        addView(ImageView(context).apply {
            setImageDrawable(icon)
            scaleType = ImageView.ScaleType.FIT_CENTER
            layoutParams = LinearLayout.LayoutParams(dp(64), dp(64)).apply { setMargins(0, 0, 0, dp(10)) }
        })
        addView(TextView(context).apply {
            text = textValue
            textSize = 14f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
            maxLines = 2
        })
    }

    private fun infoTile(textValue: String): TextView = TextView(this).apply {
        text = textValue
        textSize = 14f
        setTextColor(Color.WHITE)
        gravity = Gravity.CENTER
        background = roundedDrawable(Color.argb(80, 255, 255, 255), dp(18))
        setPadding(dp(16), dp(16), dp(16), dp(16))
        layoutParams = GridLayout.LayoutParams().apply {
            width = tileWidth()
            height = dp(142)
            setMargins(dp(8), dp(8), dp(8), dp(8))
        }
    }

    private fun defaultBackgroundDrawable(): GradientDrawable = GradientDrawable(
        GradientDrawable.Orientation.TOP_BOTTOM,
        intArrayOf(Color.rgb(226, 47, 31), Color.rgb(110, 25, 20)),
    )

    private fun roundedDrawable(color: Int, radius: Int): GradientDrawable = GradientDrawable().apply {
        setColor(color)
        cornerRadius = radius.toFloat()
    }

    private fun calculateColumnCount(): Int {
        val widthDp = resources.configuration.screenWidthDp
        return when {
            widthDp >= 1000 -> 5
            widthDp >= 700 -> 4
            else -> 3
        }
    }

    private fun tileWidth(): Int {
        val columns = calculateColumnCount()
        val screenWidth = resources.displayMetrics.widthPixels - dp(64)
        return (screenWidth / columns) - dp(16)
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
