package mx.impactosdigitales.wispcontrol_campo

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Canal de los push que manda el servidor (channel_id "avisos"), con sonido y aviso emergente.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel("avisos", "Avisos de trabajo", NotificationManager.IMPORTANCE_HIGH)
            channel.description = "Tickets, instalaciones y rutas asignadas"
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }
}
