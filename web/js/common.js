// ===================================================== Utilitas bersama
const S = { token: null, user: null, timeOffset: 0 };

function store(key, val) {
  try {
    if (val === undefined) return localStorage.getItem(key);
    if (val === null) localStorage.removeItem(key); else localStorage.setItem(key, val);
  } catch (e) { return null; }
}

function deviceId() {
  let id = store('lms_device');
  if (!id) {
    id = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    store('lms_device', id);
  }
  return id;
}

/** Memanggil API LMS (server PHP di hosting yang sama) di LMS_CONFIG.API_URL. */
async function call(action, data) {
  const url = (window.LMS_CONFIG && LMS_CONFIG.API_URL) || 'api/index.php';
  let r;
  try {
    r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: action, data: Object.assign({ token: S.token }, data || {}) }),
    });
  } catch (err) {
    throw new Error(navigator.onLine === false
      ? 'Tidak ada internet. Periksa koneksi Anda lalu coba lagi.'
      : 'Server LMS tidak bisa dihubungi. Periksa internet Anda lalu coba lagi.');
  }
  const text = await r.text();
  let res;
  try {
    res = JSON.parse(text);
  } catch (err) {
    console.error('Balasan API bukan JSON', r.status, text.slice(0, 500));
    throw new Error('Server LMS membalas tidak sesuai (status ' + r.status + '). Buka api/?cek=1 untuk mengecek pemasangan.');
  }
  if (res && res.ok) return res.data;
  const msg = res && res.error ? res.error : 'Terjadi kesalahan.';
  if (/Sesi login habis/.test(msg) && S.token) { clearLogin(); showLogin(); }
  throw new Error(msg);
}

const $ = (sel, root) => (root || document).querySelector(sel);
const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

function esc(s) {
  return String(s === undefined || s === null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function render(html) { $('#app').innerHTML = html; window.scrollTo(0, 0); }

let toastTimer;
function toast(msg, type) {
  const el = $('#toast');
  el.textContent = msg;
  el.className = 'toast ' + (type || '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.add('hidden'), type === 'error' ? 6000 : 3000);
}

function openModal(html) {
  $('#modal-box').innerHTML = html;
  $('#modal').classList.remove('hidden');
}
function closeModal() {
  $('#modal').classList.add('hidden');
  $('#modal-box').innerHTML = '';
  if (window.onModalClose) { const f = window.onModalClose; window.onModalClose = null; f(); }
}
document.addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });

/** Menjalankan aksi async sambil menonaktifkan tombol; error ditampilkan sebagai toast. */
async function busy(btn, fn, label) {
  const old = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = label || 'Memproses…'; }
  try { return await fn(); }
  catch (e) { toast(e.message, 'error'); }
  finally { if (btn && btn.isConnected) { btn.disabled = false; btn.innerHTML = old; } }
}

// ----------------------------------------------------- Waktu (WIB, mengikuti jam server)
function serverNow() { return new Date(Date.now() + S.timeOffset); }
function syncTime(serverMs) { if (serverMs) S.timeOffset = serverMs - Date.now(); }

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/** 'yyyy-mm-dd' -> 'Kamis, 30 Okt 2026' */
function fmtTanggal(s) {
  if (!s) return '';
  const [y, m, d] = s.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  return HARI[dt.getUTCDay()] + ', ' + d + ' ' + BULAN[m - 1] + ' ' + y;
}

/** Date -> bagian-bagian waktu WIB */
function wib(date) {
  const p = {};
  new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).formatToParts(date).forEach(x => { p[x.type] = x.value; });
  const tanggal = p.year + '-' + p.month + '-' + p.day;
  return { tanggal: tanggal, jam: p.hour + ':' + p.minute + ':' + p.second, teks: fmtTanggal(tanggal) + ' ' + p.hour + ':' + p.minute + ':' + p.second + ' WIB' };
}

function jamSaja(stamp) { return stamp ? stamp.slice(11, 16) : ''; }

/** Kategori materi (sama dengan server). */
const KATEGORI_MATERI = { materi: 'Materi presentasi', modul: 'Modul', bahan_ajar: 'Bahan ajar', latihan: 'Lembar latihan' };

const STATUS_LABEL = { hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa', belum: 'Belum absen', '': '—' };
function badge(status) {
  return '<span class="badge b-' + (status || 'none') + '">' + esc(STATUS_LABEL[status] || status) + '</span>';
}

/** Pengganti thumbnail yang gagal dimuat (mis. file Drive baru belum siap atau koneksi lambat). */
function fotoGagal(img) {
  img.style.display = 'none';
  img.parentNode.classList.add('noimg');
}

/** Memperkecil gambar (sisi terpanjang maks `max` px) dan mengubahnya menjadi JPEG data URL. */
function kompresGambar(file, max, quality) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * scale);
      c.height = Math.round(img.naturalHeight * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(img.src);
      resolve(c.toDataURL('image/jpeg', quality));
    };
    img.onerror = () => reject(new Error('Bukan file gambar yang valid.'));
    img.src = URL.createObjectURL(file);
  });
}

// ----------------------------------------------------- GPS
function getPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('Perangkat tidak mendukung GPS.'));
    navigator.geolocation.getCurrentPosition(
      p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, akurasi: p.coords.accuracy }),
      err => reject(new Error(err.code === 1
        ? 'Izin lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan browser, lalu coba lagi.'
        : 'Lokasi tidak terbaca. Pastikan GPS aktif, lalu coba lagi.')),
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });
}

function distanceM(lat1, lng1, lat2, lng2) {
  const R = 6371000, rad = x => x * Math.PI / 180;
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
  return Math.round(2 * R * Math.asin(Math.sqrt(a)));
}

// ----------------------------------------------------- Login
function saveLogin(res) {
  S.token = res.token; S.user = res.user;
  store('lms_token', res.token);
}
function clearLogin() {
  if (S.user) store('lms_cache_' + S.user.id_user, null);
  S.token = null; S.user = null; store('lms_token', null);
}

/** Data terakhir yang tersimpan di perangkat, agar halaman langsung tampil sambil menunggu server. */
function cacheGet(key) {
  try { return JSON.parse(store('lms_cache_' + S.user.id_user) || '{}')[key] || null; } catch (e) { return null; }
}
function cacheSet(key, val) {
  try {
    const all = JSON.parse(store('lms_cache_' + S.user.id_user) || '{}');
    all[key] = val;
    store('lms_cache_' + S.user.id_user, JSON.stringify(all));
  } catch (e) { /* penyimpanan penuh / tidak tersedia: abaikan */ }
}

async function logoutNow() {
  try { await call('logout'); } catch (e) { /* tetap keluar */ }
  clearLogin();
  showLogin();
}

function setTopbar(sub, actionsHtml) {
  $('#topbar-sub').textContent = sub ? '👤 ' + sub : '';
  $('#topbar-actions').innerHTML = actionsHtml || '';
  document.body.classList.remove('login-mode');
}

async function boot() {
  S.token = store('lms_token');
  if (!S.token) return showLogin();
  try {
    const r = await call('me');
    S.user = r.user;
    route(r);
  } catch (e) {
    clearLogin();
    showLogin();
  }
}

function route(meRes) {
  if (S.user.role === 'peserta') showPeserta(meRes); else showPic(meRes);
}

async function showLogin(tab) {
  tab = tab || store('lms_login_tab') || 'peserta';
  setTopbar('', '');
  document.body.classList.add('login-mode');
  render(`
    <div class="login-wrap">
      <section class="login-hero">
        <img class="login-logo" src="${ASET.logo}" alt="Artha Safety Indonesia">
        <img class="login-foto" src="${ASET.foto}" alt="Safety Champions Artha Safety Indonesia">
      </section>
      <section class="card login-card">
        <h2>Masuk ke akun Anda</h2>
        <p class="muted small">Pilih jenis akun, lalu masukkan kode Anda.</p>
        <div class="seg">
          <button class="${tab === 'peserta' ? 'active' : ''}" onclick="showLogin('peserta')">👷 Peserta</button>
          <button class="${tab === 'pic' ? 'active' : ''}" onclick="showLogin('pic')">🛡️ PIC / Admin</button>
        </div>
        <div id="login-form"><div class="muted small">Memuat…</div></div>
        <p class="login-foot">© ${serverNow().getFullYear()} Artha Safety Indonesia</p>
      </section>
    </div>`);
  store('lms_login_tab', tab);
  if (tab === 'pic') {
    $('#login-form').innerHTML = `
      <form onsubmit="event.preventDefault(); doLoginPic(this)">
        <label>Kode PIC</label><input name="kode" autocomplete="username" required>
        <label>PIN</label><input name="pin" type="password" inputmode="numeric" autocomplete="current-password" required>
        <button class="btn primary block big mt">Masuk</button>
      </form>`;
    return;
  }
  try {
    const batches = await call('batchAktif');
    if (!batches.length) {
      $('#login-form').innerHTML = '<div class="alert warn">Belum ada batch pelatihan yang aktif.</div>';
      return;
    }
    const last = store('lms_batch');
    $('#login-form').innerHTML = `
      <form onsubmit="event.preventDefault(); doLoginPeserta(this)">
        <label>Batch pelatihan</label>
        <select name="id_batch" ${batches.length === 1 ? 'disabled' : ''}>
          ${batches.map(b => `<option value="${esc(b.id_batch)}" ${b.id_batch === last ? 'selected' : ''}>${esc(b.kode_batch)} — ${esc(b.nama_pelatihan)}${b.selesai ? ' (selesai — unduh SKL)' : ''}</option>`).join('')}
        </select>
        <label>Kode peserta</label>
        <input name="kode" placeholder="contoh: 001-Kikis" autocapitalize="off" autocomplete="off" required>
        <p class="small muted">Kode ada di kartu peserta dari PIC (nomor absen + nama depan).</p>
        <button class="btn primary block big">Masuk</button>
      </form>`;
  } catch (e) {
    $('#login-form').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>';
  }
}

function doLoginPeserta(form) {
  const id_batch = form.id_batch.value;
  busy(form.querySelector('button'), async () => {
    const res = await call('loginPeserta', { id_batch: id_batch, kode: form.kode.value, device_id: deviceId() });
    store('lms_batch', id_batch);
    saveLogin(res);
    route(res.me);
  });
}

function doLoginPic(form) {
  busy(form.querySelector('button'), async () => {
    const res = await call('loginPic', { kode: form.kode.value, pin: form.pin.value });
    saveLogin(res);
    route(res.me);
  });
}
