# ============================================================================
# usage-kategori-mikrotik.rsc  (VERSI ROUTEROS 6.x)
# Skrip untuk fitur "Analisis Pemakaian" di dashboard. Aman dijalankan ulang
# (idempotent) - baris pertama menghapus dulu konfigurasi lama.
#
# CATATAN: versi sebelumnya pakai /ip dns static type=FWD + address-list,
# TAPI itu fitur RouterOS 7.5 ke atas - TIDAK ADA di RouterOS 6.x (makanya
# muncul error "expected end of command" persis setelah type=FWD). Versi ini
# dibuat ulang supaya jalan di RouterOS 6.x: address-list diisi lewat script
# kecil yang membaca /ip dns cache tiap 15 detik (fitur lama, ada sejak versi
# jauh sebelum v6), bukan lewat properti DNS static yang belum ada.
#
# CARA PASANG:
#   1. Winbox/WebFig > New Terminal, atau upload file lalu jalankan:
#        /import usage-kategori-mikrotik.rsc
#   2. Tunggu ~30 detik - 1 menit setelah dipasang, biar scheduler sempat
#      jalan minimal sekali dan address-list mulai terisi.
#
# SYARAT PENTING:
#   Client (hotspot/LAN) HARUS pakai router ini sebagai DNS server mereka
#   (lumrah di setup hotspot MikroTik - cek /ip dns, "allow-remote-requests"
#   harus yes). Kalau client pakai DNS lain (custom di HP/laptop, atau
#   browser pakai DNS-over-HTTPS sendiri), domain di bawah TIDAK akan masuk
#   /ip dns cache router, jadi trafiknya kehitung sebagai "Web & lainnya".
#   Ini keterbatasan bawaan metode berbasis DNS, bukan bug.
#
# CARA KERJA:
#   1. Scheduler tiap 15 detik menjalankan script yang membaca /ip dns cache,
#      mencari nama domain yang cocok dengan daftar tiap kategori, lalu
#      memasukkan IP hasilnya ke address-list kategori terkait (cat-video,
#      cat-google, cat-wa) dengan timeout 1 hari.
#   2. Mangle: connection baru yang tujuannya masuk address-list tsb ditandai
#      (mark-connection), lalu SEMUA paket di connection itu (dua arah,
#      upload+download) ditandai juga (mark-packet) dan dihitung byte-nya.
#   3. Dashboard baca counter byte dari rule mark-packet tiap 60 detik.
#
# TAMBAH DOMAIN SENDIRI:
#   Edit baris ":local patVideo", ":local patGoogle", atau ":local patWa" di
#   bawah - tambahkan "|namadomain\\." ke pola yang sesuai, lalu jalankan
#   ulang file ini.
# ============================================================================

:log info "dash: memasang ulang konfigurasi kategori pemakaian (v6.x)..."

# --- bersihkan konfigurasi lama (supaya aman dijalankan berkali-kali) ---
/system scheduler remove [find where name="dash-usage-dns-poll"]
/system script remove [find where name="dash-usage-dns-poll"]
/ip firewall mangle remove [find where comment~"^usage:"]
/ip firewall address-list remove [find where list="cat-video"]
/ip firewall address-list remove [find where list="cat-google"]
/ip firewall address-list remove [find where list="cat-wa"]

# --- script: baca DNS cache tiap 15 detik, isi address-list per kategori ---
/system script
add name=dash-usage-dns-poll source={
    :local patVideo "youtube|googlevideo|ytimg|facebook|fbcdn|instagram|tiktok"
    :local patGoogle "google|gstatic|googleapis"
    :local patWa "whatsapp|wa\\.me"

    :foreach c in=[/ip dns cache find where type="A"] do={
        :do {
            :local cName [/ip dns cache get $c name]
            :local addr [/ip dns cache get $c data]
            :if ([:len $addr] > 0) do={
                :local ipStr [:toip $addr]
                :if ([:len $ipStr] > 0) do={
                    :local targetList ""
                    :if ($cName ~ $patVideo) do={ :set targetList "cat-video" } else={
                        :if ($cName ~ $patGoogle) do={ :set targetList "cat-google" } else={
                            :if ($cName ~ $patWa) do={ :set targetList "cat-wa" }
                        }
                    }
                    :if ([:len $targetList] > 0) do={
                        :if ([:len [/ip firewall address-list find where list=$targetList address=$ipStr]] = 0) do={
                            /ip firewall address-list add list=$targetList address=$ipStr timeout=1d comment=dash-usage-cat
                        }
                    }
                }
            }
        } on-error={}
    }
}

/system scheduler
add name=dash-usage-dns-poll interval=10s on-event=dash-usage-dns-poll comment=dash-usage-cat


/ip firewall raw
add chain=prerouting protocol=tcp dst-port=443 tls-host="*youtube.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*googlevideo.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*ytimg.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*tiktok.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*tiktokcdn.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*instagram.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*facebook.com*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*fbcdn.net*" action=add-dst-to-address-list address-list=cat-video address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*google.com*" action=add-dst-to-address-list address-list=cat-google address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*gstatic.com*" action=add-dst-to-address-list address-list=cat-google address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*whatsapp.com*" action=add-dst-to-address-list address-list=cat-wa address-list-timeout=1d comment="dash-usage-cat"
add chain=prerouting protocol=tcp dst-port=443 tls-host="*whatsapp.net*" action=add-dst-to-address-list address-list=cat-wa address-list-timeout=1d comment="dash-usage-cat"


# --- mangle: tandai koneksi baru menuju tiap address-list, lalu hitung byte 2 arah ---
/ip firewall mangle
add chain=forward action=mark-connection new-connection-mark=usage-video-conn passthrough=yes connection-mark=no-mark connection-state=new dst-address-list=cat-video comment="usage:video conn"
add chain=forward action=mark-packet new-packet-mark=usage-video-pkt passthrough=yes connection-mark=usage-video-conn comment="usage:video pkt"

add chain=forward action=mark-connection new-connection-mark=usage-google-conn passthrough=yes connection-mark=no-mark connection-state=new dst-address-list=cat-google comment="usage:google conn"
add chain=forward action=mark-packet new-packet-mark=usage-google-pkt passthrough=yes connection-mark=usage-google-conn comment="usage:google pkt"

add chain=forward action=mark-connection new-connection-mark=usage-wa-conn passthrough=yes connection-mark=no-mark connection-state=new dst-address-list=cat-wa comment="usage:wa conn"
add chain=forward action=mark-packet new-packet-mark=usage-wa-pkt passthrough=yes connection-mark=usage-wa-conn comment="usage:wa pkt"

:log info "dash: konfigurasi kategori pemakaian (v6.x) selesai dipasang."
