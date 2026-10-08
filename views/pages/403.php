<div class="card">
    <h2>🚫 Akses Ditolak</h2>
    <p style="color:var(--muted);">
        Role <b><?= e(ROLES[user_role()] ?? user_role()) ?></b> tidak memiliki hak akses untuk halaman ini.
        Silakan hubungi Owner/Admin bila Anda membutuhkan akses tambahan.
    </p>
    <a class="btn btn-primary" href="<?= e(app_url(landing_page())) ?>">Kembali ke halaman utama saya</a>
</div>
