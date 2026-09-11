# ============================================================================
# usage-kategori-mikrotik.rsc
# UNIVERSAL ROUTEROS 6.x + ROUTEROS 7.x
#
# SUPPORT:
#   RouterOS 6.x
#   RouterOS 7.5+
#
# ============================================================================
#
# FITUR:
#
#   Analisis Pemakaian Dashboard DZW
#
#   Kategori:
#       cat-video
#       cat-google
#       cat-wa
#
#   Packet Mark:
#       usage-video-pkt
#       usage-google-pkt
#       usage-wa-pkt
#
#
# ============================================================================
# ROUTEROS 6.x
# ============================================================================
#
# METODE ROS6 TIDAK DIUBAH:
#
#   Client
#      |
#      v
#   DNS MikroTik
#      |
#      v
#   /ip dns cache
#      |
#      v
#   dash-usage-dns-poll
#      |
#      v
#   Address List
#      |
#      v
#   Mangle
#      |
#      v
#   Dashboard
#
# Ditambah RAW TLS Host seperti konfigurasi lama.
#
#
# ============================================================================
# ROUTEROS 7.5+
# ============================================================================
#
# Menggunakan:
#
#   /ip dns static
#       type=FWD
#       match-subdomain=yes
#       address-list=
#
# Alur:
#
#   Client
#      |
#      v
#   DNS MikroTik
#      |
#      v
#   DNS Static FWD
#      |
#      v
#   DNS upstream
#      |
#      v
#   IP masuk dynamic address-list
#      |
#      v
#   Mangle
#      |
#      v
#   Dashboard
#
# RouterOS mendukung DNS Static FWD dan address-list dinamis berdasarkan
# hasil resolusi DNS. Entry address-list akan mengikuti TTL DNS.
#
# Untuk menjaga entry tidak terlalu cepat hilang, static DNS entry diberi
# ttl=1d.
#
#
# ============================================================================
# CARA PASANG
# ============================================================================
#
# Upload:
#
#   usage-kategori-mikrotik.rsc
#
# Kemudian:
#
#   /import usage-kategori-mikrotik.rsc
#
#
# ============================================================================
# AMAN DIJALANKAN ULANG
# ============================================================================
#
# Script menghapus konfigurasi yang dibuat oleh script ini:
#
#   Scheduler:
#       dash-usage-dns-poll
#
#   Script:
#       dash-usage-dns-poll
#
#   Mangle:
#       comment dimulai "usage:"
#
#   RAW:
#       comment "dash-usage-cat"
#
#   Address-list:
#       cat-video
#       cat-google
#       cat-wa
#
#   DNS Static ROS7:
#       comment "dash-usage-dns"
#
# DNS Static konfigurasi lain TIDAK disentuh.
#
#
# ============================================================================
# SYARAT
# ============================================================================
#
# Client harus menggunakan MikroTik sebagai DNS.
#
# Pastikan:
#
#   /ip dns set allow-remote-requests=yes
#
# Jika client menggunakan:
#
#   8.8.8.8
#   1.1.1.1
#   9.9.9.9
#
# atau:
#
#   Private DNS
#   DNS-over-HTTPS
#   DNS-over-TLS
#
# maka domain bisa tidak terlihat oleh resolver MikroTik.
#
#
# ============================================================================
# MULAI SCRIPT
# ============================================================================


:log info "dash: ================================================"
:log info "dash: INSTALL USAGE KATEGORI"
:log info "dash: ================================================"


# ============================================================================
# DETEKSI ROUTEROS
# ============================================================================

:local rosVersion [/system resource get version]
:local rosMajor [:pick $rosVersion 0 1]

:log info ("dash: RouterOS = " . $rosVersion)


# ============================================================================
# VALIDASI ROUTEROS
# ============================================================================

:if (($rosMajor != "6") && ($rosMajor != "7")) do={

    :log warning ("dash: RouterOS tidak didukung: " . $rosVersion)

    :error ("RouterOS tidak didukung: " . $rosVersion)

}


# ============================================================================
# CLEANUP
# ============================================================================

:log info "dash: membersihkan konfigurasi lama..."


# ----------------------------------------------------------------------------
# Scheduler ROS6
# ----------------------------------------------------------------------------

/system scheduler remove [
    find where name="dash-usage-dns-poll"
]


# ----------------------------------------------------------------------------
# Script ROS6
# ----------------------------------------------------------------------------

/system script remove [
    find where name="dash-usage-dns-poll"
]


# ----------------------------------------------------------------------------
# Mangle
# ----------------------------------------------------------------------------

/ip firewall mangle remove [
    find where comment~"^usage:"
]


# ----------------------------------------------------------------------------
# RAW
# ----------------------------------------------------------------------------

/ip firewall raw remove [
    find where comment="dash-usage-cat"
]


# ----------------------------------------------------------------------------
# Address List
# ----------------------------------------------------------------------------

/ip firewall address-list remove [
    find where list="cat-video"
]


/ip firewall address-list remove [
    find where list="cat-google"
]


/ip firewall address-list remove [
    find where list="cat-wa"
]


# ----------------------------------------------------------------------------
# DNS STATIC KHUSUS SCRIPT
#
# Hanya dilakukan di ROS7.
# Tidak menyentuh DNS Static lain.
# ----------------------------------------------------------------------------

:if ($rosMajor = "7") do={

    /ip dns static remove [
        find where comment="dash-usage-dns"
    ]

}


# ============================================================================
# ROUTEROS 6.x
# ============================================================================
#
# BAGIAN INI MEMPERTAHANKAN METODE ROS6 YANG SUDAH FIX.
# ============================================================================

:if ($rosMajor = "6") do={

    :log info "dash: ================================================"
    :log info "dash: ROUTEROS 6 MODE"
    :log info "dash: DNS CACHE POLLING"
    :log info "dash: ================================================"


    # ========================================================================
    # DNS CACHE POLLING SCRIPT
    # ========================================================================

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


                        # ====================================================
                        # VIDEO / SOCIAL
                        # ====================================================

                        :if ($cName ~ $patVideo) do={

                            :set targetList "cat-video"

                        } else={


                            # =================================================
                            # GOOGLE
                            # =================================================

                            :if ($cName ~ $patGoogle) do={

                                :set targetList "cat-google"

                            } else={


                                # =============================================
                                # WHATSAPP
                                # =============================================

                                :if ($cName ~ $patWa) do={

                                    :set targetList "cat-wa"

                                }

                            }

                        }


                        # ====================================================
                        # ADD ADDRESS LIST
                        # ====================================================

                        :if ([:len $targetList] > 0) do={

                            :if (
                                [:len [
                                    /ip firewall address-list find \
                                    where list=$targetList address=$ipStr
                                ]] = 0
                            ) do={

                                /ip firewall address-list add \
                                    list=$targetList \
                                    address=$ipStr \
                                    timeout=1d \
                                    comment=dash-usage-cat

                            }

                        }

                    }

                }

            } on-error={}

        }

    }


    # ========================================================================
    # SCHEDULER
    # ========================================================================

    /system scheduler

    add name=dash-usage-dns-poll \
        interval=10s \
        on-event=dash-usage-dns-poll \
        comment=dash-usage-cat


    # ========================================================================
    # RAW TLS HOST
    # ========================================================================

    /ip firewall raw


    # ------------------------------------------------------------------------
    # YOUTUBE
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*youtube.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # GOOGLE VIDEO
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*googlevideo.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # YTIMG
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*ytimg.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # TIKTOK
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*tiktok.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # TIKTOK CDN
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*tiktokcdn.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # INSTAGRAM
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*instagram.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # FACEBOOK
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*facebook.com*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # FACEBOOK CDN
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*fbcdn.net*" \
        action=add-dst-to-address-list \
        address-list=cat-video \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # GOOGLE
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*google.com*" \
        action=add-dst-to-address-list \
        address-list=cat-google \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # GSTATIC
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*gstatic.com*" \
        action=add-dst-to-address-list \
        address-list=cat-google \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # WHATSAPP
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*whatsapp.com*" \
        action=add-dst-to-address-list \
        address-list=cat-wa \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    # ------------------------------------------------------------------------
    # WHATSAPP NET
    # ------------------------------------------------------------------------

    add chain=prerouting \
        protocol=tcp \
        dst-port=443 \
        tls-host="*whatsapp.net*" \
        action=add-dst-to-address-list \
        address-list=cat-wa \
        address-list-timeout=1d \
        comment="dash-usage-cat"


    :log info "dash: ROS6 DNS CACHE berhasil."

}


# ============================================================================
# ROUTEROS 7.x
#
# TARGET:
#   RouterOS 7.5+
#
# METODE:
#   DNS STATIC FWD
#
# ============================================================================

:if ($rosMajor = "7") do={

    :log info "dash: ================================================"
    :log info "dash: ROUTEROS 7 MODE"
    :log info "dash: DNS STATIC FWD"
    :log info "dash: ================================================"


    # ========================================================================
    # ENABLE DNS REMOTE REQUEST
    #
    # Tidak mengubah DNS upstream.
    # ========================================================================

    /ip dns set allow-remote-requests=yes


    # ========================================================================
    # VIDEO / SOCIAL
    #
    # match-subdomain=yes:
    #
    # youtube.com
    # www.youtube.com
    # m.youtube.com
    # dll
    #
    # ========================================================================

    /ip dns static


    # ------------------------------------------------------------------------
    # YOUTUBE
    # ------------------------------------------------------------------------

    add name="youtube.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # GOOGLE VIDEO
    # ------------------------------------------------------------------------

    add name="googlevideo.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # YTIMG
    # ------------------------------------------------------------------------

    add name="ytimg.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # TIKTOK
    # ------------------------------------------------------------------------

    add name="tiktok.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # TIKTOK CDN
    # ------------------------------------------------------------------------

    add name="tiktokcdn.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # INSTAGRAM
    # ------------------------------------------------------------------------

    add name="instagram.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # FACEBOOK
    # ------------------------------------------------------------------------

    add name="facebook.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # FACEBOOK CDN
    # ------------------------------------------------------------------------

    add name="fbcdn.net" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-video \
        ttl=1d \
        comment="dash-usage-dns"


    # ========================================================================
    # GOOGLE
    # ========================================================================


    # ------------------------------------------------------------------------
    # GOOGLE
    # ------------------------------------------------------------------------

    add name="google.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-google \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # GSTATIC
    # ------------------------------------------------------------------------

    add name="gstatic.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-google \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # GOOGLE APIs
    # ------------------------------------------------------------------------

    add name="googleapis.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-google \
        ttl=1d \
        comment="dash-usage-dns"


    # ========================================================================
    # WHATSAPP
    # ========================================================================


    # ------------------------------------------------------------------------
    # WHATSAPP.COM
    # ------------------------------------------------------------------------

    add name="whatsapp.com" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-wa \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # WHATSAPP.NET
    # ------------------------------------------------------------------------

    add name="whatsapp.net" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-wa \
        ttl=1d \
        comment="dash-usage-dns"


    # ------------------------------------------------------------------------
    # WA.ME
    # ------------------------------------------------------------------------

    add name="wa.me" \
        type=FWD \
        match-subdomain=yes \
        address-list=cat-wa \
        ttl=1d \
        comment="dash-usage-dns"


    :log info "dash: ROS7 DNS STATIC FWD berhasil."


}


# ============================================================================
# MANGLE
#
# BAGIAN INI SAMA UNTUK ROS6 DAN ROS7.
#
# JANGAN UBAH NAMA:
#
#   usage-video-conn
#   usage-video-pkt
#   usage-google-conn
#   usage-google-pkt
#   usage-wa-conn
#   usage-wa-pkt
#
# Karena dashboard membaca counter dari packet-mark tersebut.
# ============================================================================

/ip firewall mangle


# ============================================================================
# VIDEO CONNECTION
# ============================================================================

add chain=forward \
    action=mark-connection \
    new-connection-mark=usage-video-conn \
    passthrough=yes \
    connection-mark=no-mark \
    connection-state=new \
    dst-address-list=cat-video \
    comment="usage:video conn"


# ============================================================================
# VIDEO PACKET
# ============================================================================

add chain=forward \
    action=mark-packet \
    new-packet-mark=usage-video-pkt \
    passthrough=yes \
    connection-mark=usage-video-conn \
    comment="usage:video pkt"


# ============================================================================
# GOOGLE CONNECTION
# ============================================================================

add chain=forward \
    action=mark-connection \
    new-connection-mark=usage-google-conn \
    passthrough=yes \
    connection-mark=no-mark \
    connection-state=new \
    dst-address-list=cat-google \
    comment="usage:google conn"


# ============================================================================
# GOOGLE PACKET
# ============================================================================

add chain=forward \
    action=mark-packet \
    new-packet-mark=usage-google-pkt \
    passthrough=yes \
    connection-mark=usage-google-conn \
    comment="usage:google pkt"


# ============================================================================
# WHATSAPP CONNECTION
# ============================================================================

add chain=forward \
    action=mark-connection \
    new-connection-mark=usage-wa-conn \
    passthrough=yes \
    connection-mark=no-mark \
    connection-state=new \
    dst-address-list=cat-wa \
    comment="usage:wa conn"


# ============================================================================
# WHATSAPP PACKET
# ============================================================================

add chain=forward \
    action=mark-packet \
    new-packet-mark=usage-wa-pkt \
    passthrough=yes \
    connection-mark=usage-wa-conn \
    comment="usage:wa pkt"


# ============================================================================
# HASIL AKHIR
# ============================================================================

:if ($rosMajor = "6") do={

    :log info "dash: ================================================"
    :log info "dash: USAGE KATEGORI AKTIF - ROUTEROS 6.x"
    :log info "dash: METODE = DNS CACHE + RAW TLS"
    :log info "dash: ================================================"

}


:if ($rosMajor = "7") do={

    :log info "dash: ================================================"
    :log info "dash: USAGE KATEGORI AKTIF - ROUTEROS 7.x"
    :log info "dash: METODE = DNS STATIC FWD"
    :log info "dash: ================================================"

}


:log info "dash: instalasi usage kategori selesai."


# ============================================================================
# END
# ============================================================================
