// ===================================================== Halaman peserta
const P = { batch: null, status: null, konten: null, tab: 'beranda' };

function showPeserta(meRes) {
  P.batch = meRes.batch;
  if (meRes.beranda) {
    cacheSet('beranda', meRes.beranda);
    P.segar = meRes.beranda; // data baru dari server, tidak perlu dimuat ulang
  }
  setTopbar(S.user.nama + ' · ' + S.user.kode_login, '<button class="btn sm" onclick="logoutNow()">Keluar</button>');
  if (!S.user.setuju_privasi) return showPersetujuan();
  pesertaTab(P.tab);
}

function showPersetujuan() {
  render(`
    <div class="card narrow">
      <h1>Selamat datang, ${esc(S.user.nama)}</h1>
      <p class="muted">${esc(P.batch.kode_batch)} — ${esc(P.batch.nama_pelatihan)}</p>
      <div class="alert info small">
        Untuk absensi, LMS ini akan merekam <b>foto wajah</b>, <b>tanda tangan</b>, dan <b>lokasi GPS</b> Anda
        setiap sesi. Data hanya digunakan untuk keperluan administrasi dan laporan pelatihan
        Artha Safety Indonesia, dan hanya dapat dilihat oleh PIC pelatihan.
      </div>
      <label class="check"><input type="checkbox" id="setuju-cb"> Saya mengerti dan menyetujui penggunaan data tersebut.</label>
      <button class="btn primary block big mt" onclick="doSetuju(this)">Lanjutkan</button>
    </div>`);
}

function doSetuju(btn) {
  if (!$('#setuju-cb').checked) return toast('Centang persetujuan terlebih dahulu.', 'error');
  busy(btn, async () => {
    S.user = await call('setuju');
    pesertaTab('beranda');
  });
}

const NAV_PESERTA = [
  ['beranda', '🏠', 'Dashboard'], ['rundown', '🗓️', 'Jadwal & Rundown'], ['materi', '📚', 'Materi'],
  ['dok', '📷', 'Dokumentasi'], ['riwayat', '✅', 'Riwayat Absen'],
];

function pesertaTab(tab) {
  P.tab = tab;
  const aktif = NAV_PESERTA.find(n => n[0] === tab);
  render(`
    <div class="shell">
      <nav class="sidenav">
        ${NAV_PESERTA.map(n => `<button class="navlink ${tab === n[0] ? 'active' : ''}" onclick="pesertaTab('${n[0]}')">
          <span class="nav-ic">${n[1]}</span><span>${n[2]}</span></button>`).join('')}
      </nav>
      <section class="shell-main">
        <div class="page-head">
          <h1>${tab === 'beranda' ? 'Selamat Datang di Dashboard Peserta' : aktif[2]}</h1>
          ${tab === 'beranda' ? `<p class="muted">Halo, ${esc(S.user.nama)} 👋</p>` : ''}
        </div>
        <div id="tab-body"><div class="center muted pad">Memuat…</div></div>
      </section>
    </div>`);
  if (tab === 'beranda') loadBeranda();
  else if (tab === 'riwayat') loadRiwayat();
  else loadKonten(tab);
}

function tampilBeranda(b) {
  P.status = b.status;
  P.konten = b.konten;
  $('#tab-body').innerHTML = berandaHtml(b.status, b.konten) + kontenHariIniHtml(b.konten);
}

/** Tampilkan data tersimpan dulu (instan), lalu ganti dengan data terbaru dari server. */
async function loadBeranda() {
  if (P.segar) {
    const b = P.segar;
    P.segar = null;
    syncTime(b.status.server_now);
    return tampilBeranda(b);
  }
  const lama = cacheGet('beranda');
  if (lama) tampilBeranda(lama);
  try {
    const b = await call('beranda');
    syncTime(b.status.server_now);
    cacheSet('beranda', b);
    if (P.tab !== 'beranda' || !$('#modal').classList.contains('hidden')) return;
    tampilBeranda(b);
  } catch (e) {
    if (!lama) $('#tab-body').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>';
    else toast(e.message, 'error');
  }
}

function berandaHtml(st, k) {
  return heroHtml(st, k) + statHtml(st) + (st.hari ? `
    <h2 class="section-title">Absensi Hari Ini</h2>
    <div class="grid2">${st.hari.sesi.map(sesiCardHtml).join('')}</div>` : '');
}

/** Kartu utama bergradasi: hari ini, sesi yang sedang dibuka, dan kegiatan yang sedang berjalan. */
function heroHtml(st, k) {
  const b = st.batch;
  let chip, judul, sub, aksi = '', now = '';
  if (st.hari) {
    const h = st.hari;
    const buka = h.sesi.find(s => s.state === 'buka' && s.status !== 'hadir');
    chip = buka ? `<span class="chip chip-live">● Absen Sesi ${buka.sesi} dibuka</span>`
      : `<span class="chip chip-glass">Hari ke-${esc(h.hari_ke)} dari ${esc(h.total_hari)}</span>`;
    judul = `${esc(b.nama_pelatihan)} – Hari Ke-${esc(h.hari_ke)}`;
    sub = `${esc(fmtTanggal(st.tanggal))} · ${esc(h.sesi[0].mulai)}–${esc(h.sesi[h.sesi.length - 1].selesai)} WIB · 📍 ${esc(h.lokasi.nama)}`;
    if (buka) aksi = `<button class="btn white big" onclick="mulaiAbsen(${buka.sesi})">📸 Absen Sesi ${buka.sesi} Sekarang</button>`;
    const hk = k.hari.find(x => x.tanggal === k.tanggal);
    const jalan = hk && hk.rundown.find(r => k.jam >= r.jam_mulai && k.jam < r.jam_selesai);
    if (jalan) now = `<div class="hero-now">▶ Sedang berlangsung: <b>${esc(jalan.kegiatan)}</b> (${esc(jalan.jam_mulai)}–${esc(jalan.jam_selesai)})</div>`;
  } else {
    chip = '<span class="chip chip-glass">Tidak ada jadwal hari ini</span>';
    judul = esc(b.nama_pelatihan);
    sub = st.berikutnya ? 'Jadwal pelatihan berikutnya: <b>' + esc(fmtTanggal(st.berikutnya)) + '</b>'
      : st.hari_selesai ? 'Pelatihan telah selesai. Terima kasih atas partisipasi Anda!' : '';
  }
  return `
    <div class="hero">
      <div class="hero-text">${chip}<h2 class="hero-title">${judul}</h2><div class="hero-sub">${sub}</div>${now}</div>
      ${aksi ? `<div class="hero-aksi">${aksi}</div>` : ''}
    </div>`;
}

function statHtml(st) {
  const b = st.batch, r = st.rekap;
  const pct = r.total ? Math.round(r.hadir / r.total * 100) + '%' : '–';
  const hariKe = st.hari ? Number(st.hari.hari_ke) : st.hari_selesai;
  const progres = st.total_hari ? Math.round(hariKe / st.total_hari * 100) : 0;
  const status = st.hari ? 'Sedang berlangsung' : st.berikutnya && !st.hari_selesai ? 'Belum dimulai' : st.berikutnya ? 'Jeda' : 'Selesai';
  const tgl = b.tgl_mulai === b.tgl_selesai ? fmtTanggal(b.tgl_mulai) : fmtTanggal(b.tgl_mulai).replace(/^\w+, /, '') + ' – ' + fmtTanggal(b.tgl_selesai).replace(/^\w+, /, '');
  return `
    <div class="grid3">
      <div class="card stat-card">
        <span class="chip chip-blue">Program Anda</span>
        <div class="stat-main">${esc(b.nama_pelatihan)}</div>
        <div class="small muted">Batch ${esc(b.kode_batch)} • ${esc(tgl)}</div>
      </div>
      <div class="card stat-card">
        <span class="chip chip-blue">Kehadiran Saya</span>
        <div class="mini-row">
          <div class="mini-box"><div class="mini-label">Sesi hadir</div><div class="mini-val">${r.hadir} / ${r.total}</div></div>
          <div class="mini-box"><div class="mini-label">Persentase</div><div class="mini-val">${pct}</div></div>
        </div>
      </div>
      <div class="card stat-card">
        <span class="chip chip-green">Progres Pelatihan</span>
        <div class="row" style="justify-content:space-between;margin-top:10px">
          <div><div class="mini-label">Hari ke</div><div class="stat-main" style="margin:0">${hariKe} / ${st.total_hari}</div></div>
          <div style="text-align:right"><div class="mini-label">Status</div><b>${status}</b></div>
        </div>
        <div class="progress mt"><span style="width:${progres}%"></span></div>
      </div>
    </div>`;
}

function sesiCardHtml(s) {
  const done = s.status === 'hadir';
  let body;
  if (done) {
    body = `<div class="alert ok">✅ Sudah absen pukul <b>${esc(jamSaja(s.absen.waktu))} WIB</b></div>`;
  } else if (s.state === 'buka') {
    body = (s.status !== 'belum' ? `<div class="mb">${badge(s.status)} <span class="small muted">${esc(s.absen && s.absen.keterangan || '')}</span></div>` : '') +
      `<button class="btn accent block big" onclick="mulaiAbsen(${s.sesi})">📸 Absen Sesi ${s.sesi} sekarang</button>`;
  } else if (s.state === 'belum_dibuka') {
    body = `<div class="muted small">Tombol absen aktif pukul ${esc(s.mulai)} WIB.</div>`;
  } else {
    body = `<div>${badge(s.status)} ${s.absen && s.absen.keterangan ? '<span class="small muted">' + esc(s.absen.keterangan) + '</span>' : ''}</div>`;
  }
  return `
    <div class="card sesi-card ${done ? 'done' : s.state === 'buka' ? 'buka' : ''}">
      <div class="row"><h2 class="grow">Sesi ${s.sesi} — ${s.sesi === 1 ? 'Pagi' : 'Siang'}</h2>
        <span class="sesi-time">${esc(s.mulai)}–${esc(s.selesai)} WIB</span></div>
      ${body}
    </div>`;
}

// ----------------------------------------------------- Rundown, materi, dokumentasi
function kontenHariIniHtml(k) {
  const h = k.hari.find(x => x.tanggal === k.tanggal);
  let html = '';
  if (h) {
    html += `<div class="grid2 split">
      <div class="card"><h2>🗓️ Rundown Hari Ini</h2>${rundownHtml(h.rundown, k.jam)}</div>
      <div class="card"><h2>📚 Materi Hari Ini</h2>${materiHtml(h.materi)}</div>
    </div>`;
    if (h.dok.length) html += `<div class="card"><h2>📷 Dokumentasi Hari Ini</h2>${galeriHtml(h, 6)}</div>`;
  }
  return html + `<button class="btn block" onclick="loadBeranda()">↻ Muat ulang</button>`;
}

async function loadKonten(tab) {
  const lama = P.konten || (cacheGet('beranda') || {}).konten;
  if (lama) $('#tab-body').innerHTML = kontenTabHtml(tab, lama);
  try {
    const k = await call('kontenPeserta');
    if (P.tab !== tab) return;
    P.konten = k;
    $('#tab-body').innerHTML = kontenTabHtml(tab, k);
  } catch (e) {
    if (!lama) $('#tab-body').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>';
    else toast(e.message, 'error');
  }
}

function kontenTabHtml(tab, k) {
  const hari = tab === 'rundown' ? k.hari : k.hari.filter(h => h.tanggal <= k.tanggal).reverse();
  const kosong = { rundown: 'Rundown belum diterbitkan PIC.', materi: 'Belum ada materi.', dok: 'Belum ada dokumentasi.' }[tab];
  const isi = hari.map(h => {
    const items = tab === 'rundown' ? h.rundown : tab === 'materi' ? h.materi : h.dok;
    if (!items.length) return '';
    const body = tab === 'rundown' ? rundownHtml(items, h.tanggal === k.tanggal ? k.jam : null)
      : tab === 'materi' ? materiHtml(items) : galeriHtml(h);
    return `<div class="card">
      <div class="row mb"><h2 class="grow">Hari ke-${esc(h.hari_ke)}</h2>
        <span class="small muted">${esc(fmtTanggal(h.tanggal))}${h.tanggal === k.tanggal ? ' · <b>hari ini</b>' : ''}</span></div>
      ${body}</div>`;
  }).join('');
  return isi || `<div class="card center muted">${kosong}</div>`;
}

/** jamSekarang diisi hanya untuk hari ini, untuk menandai kegiatan yang sedang berlangsung. */
function rundownHtml(items, jamSekarang) {
  if (!items.length) return '<div class="muted small">Rundown belum diterbitkan PIC.</div>';
  return `<div class="table-wrap"><table class="rundown">
    <tbody>${items.map(r => {
      const now = jamSekarang && jamSekarang >= r.jam_mulai && jamSekarang < r.jam_selesai;
      return `<tr class="${now ? 'rd-now' : ''}">
        <td class="rd-jam">${esc(r.jam_mulai)}–${esc(r.jam_selesai)}</td>
        <td><b>${esc(r.kegiatan)}</b> ${now ? '<span class="badge b-belum">Sedang berlangsung</span>' : ''}
          ${r.pemateri ? `<div class="small">👤 ${esc(r.pemateri)}</div>` : ''}
          ${r.keterangan ? `<div class="small muted">${esc(r.keterangan)}</div>` : ''}</td>
      </tr>`;
    }).join('')}</tbody></table></div>`;
}

const IKON_FILE = { pdf: '📕', ppt: '📙', pptx: '📙', doc: '📘', docx: '📘', xls: '📗', xlsx: '📗', jpg: '🖼', jpeg: '🖼', png: '🖼', link: '🔗' };

function materiHtml(items) {
  if (!items.length) return '<div class="muted small">Belum ada materi.</div>';
  return items.map(m => `
    <div class="materi-item">
      <div class="materi-ikon">${IKON_FILE[m.tipe_file] || '📄'}</div>
      <div class="grow">
        <b>${esc(m.judul)}</b>
        <div class="small muted">${m.kategori === 'latihan' ? 'Lembar latihan' : 'Materi'} · ${esc(m.tipe_file.toUpperCase())}</div>
      </div>
      <div class="row">
        <a class="btn sm" href="${esc(m.buka)}" target="_blank" rel="noopener">Buka</a>
        ${m.unduh ? `<a class="btn sm" href="${esc(m.unduh)}" target="_blank" rel="noopener">⬇</a>` : ''}
      </div>
    </div>`).join('');
}

function galeriHtml(h, max) {
  const items = max ? h.dok.slice(-max) : h.dok;
  return `<div class="gallery">${items.map(d => `
    <button class="gallery-item" onclick="lihatFoto('${esc(h.id_hari)}', '${esc(d.id_dok)}')">
      <img src="${esc(d.thumb)}" alt="${esc(d.keterangan || 'Dokumentasi')}" loading="lazy" referrerpolicy="no-referrer" onerror="fotoGagal(this)">
    </button>`).join('')}</div>
    ${max && h.dok.length > max ? `<button class="btn sm mt" onclick="pesertaTab('dok')">Lihat semua (${h.dok.length})</button>` : ''}`;
}

function lihatFoto(idHari, idDok) {
  const h = P.konten.hari.find(x => x.id_hari === idHari);
  const d = h.dok.find(x => x.id_dok === idDok);
  const keg = d.id_rundown && h.rundown.find(r => r.id_rundown === d.id_rundown);
  openModal(`
    <img class="thumb" src="${esc(d.thumb.replace('sz=w600', 'sz=w1600'))}" alt="" referrerpolicy="no-referrer">
    ${keg ? `<div class="small muted mt">${esc(keg.jam_mulai)} · ${esc(keg.kegiatan)}</div>` : ''}
    ${d.keterangan ? `<p>${esc(d.keterangan)}</p>` : ''}
    <div class="row mt"><a class="btn" href="${esc(d.buka)}" target="_blank" rel="noopener">Buka ukuran penuh</a>
      <button class="btn primary grow" onclick="closeModal()">Tutup</button></div>`);
}

async function loadRiwayat() {
  try {
    const rows = await call('riwayat');
    if (P.tab !== 'riwayat') return;
    $('#tab-body').innerHTML = `
      <div class="card table-wrap">
        <table>
          <thead><tr><th>Hari</th><th>Sesi 1</th><th>Sesi 2</th></tr></thead>
          <tbody>${rows.map(r => `
            <tr>
              <td><b>Hari ke-${esc(r.hari_ke)}</b><div class="small muted">${esc(fmtTanggal(r.tanggal))}</div></td>
              ${r.sesi.map(s => `<td>${badge(s.status)}<div class="small muted">${esc(jamSaja(s.waktu))}</div></td>`).join('')}
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    $('#tab-body').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>';
  }
}

// ----------------------------------------------------- Alur absen: lokasi → foto → tanda tangan → kirim
const A = { sesi: 0, pos: null, jarak: null, foto: null, ttd: null, stream: null, pad: null };

function stepsHtml(n) {
  return '<div class="steps">' + [1, 2, 3].map(i => `<span class="${i <= n ? 'on' : ''}"></span>`).join('') + '</div>';
}

function mulaiAbsen(sesi) {
  Object.assign(A, { sesi: sesi, pos: null, jarak: null, foto: null, ttd: null });
  window.onModalClose = stopCamera;
  stepLokasi();
}

async function stepLokasi() {
  const lok = P.status.hari.lokasi;
  openModal(`${stepsHtml(1)}
    <h2>1. Cek lokasi</h2>
    <p class="muted small">Lokasi pelatihan: <b>${esc(lok.nama)}</b> (radius ${esc(lok.radius)} m)</p>
    <div id="lok-status" class="alert info">📡 Membaca lokasi GPS…</div>
    <div class="row mt">
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn" id="lok-retry" onclick="stepLokasi()">↻ Coba lagi</button>
      <button class="btn primary grow" id="lok-next" disabled onclick="stepFoto()">Lanjut</button>
    </div>`);
  if (lok.lat === null) {
    $('#lok-status').className = 'alert bad';
    $('#lok-status').textContent = 'Lokasi pelatihan hari ini belum diatur PIC. Hubungi PIC.';
    return;
  }
  try {
    const pos = await getPosition();
    A.pos = pos;
    A.jarak = distanceM(pos.lat, pos.lng, lok.lat, lok.lng);
    const ok = A.jarak <= lok.radius;
    $('#lok-status').className = 'alert ' + (ok ? 'ok' : 'bad');
    $('#lok-status').innerHTML = ok
      ? `✅ Anda berada di lokasi (± ${A.jarak} m dari titik pelatihan, akurasi GPS ± ${Math.round(pos.akurasi)} m).`
      : `❌ Anda berada ± ${A.jarak} m dari lokasi pelatihan. Absen hanya bisa dalam radius ${lok.radius} m.
         ${pos.akurasi > 100 ? '<br><span class="small">Akurasi GPS rendah (± ' + Math.round(pos.akurasi) + ' m). Coba pindah ke area terbuka lalu coba lagi.</span>' : ''}`;
    $('#lok-next').disabled = !ok;
  } catch (e) {
    $('#lok-status').className = 'alert bad';
    $('#lok-status').textContent = e.message;
  }
}

async function stepFoto() {
  openModal(`${stepsHtml(2)}
    <h2>2. Foto selfie</h2>
    <p class="muted small">Pastikan wajah terlihat jelas.</p>
    <div id="cam-area"><div class="alert info">📷 Membuka kamera…</div></div>
    <input type="file" id="cam-file" accept="image/*" capture="user" class="hidden" onchange="fotoDariFile(this)">
    <div class="row mt" id="cam-actions">
      <button class="btn" onclick="closeModal()">Batal</button>
    </div>`);
  try {
    A.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 } }, audio: false });
    $('#cam-area').innerHTML = '<video id="cam-video" class="mirror" autoplay playsinline muted></video>';
    $('#cam-video').srcObject = A.stream;
    $('#cam-actions').innerHTML = `
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn accent grow" onclick="ambilFoto()">📸 Ambil foto</button>`;
  } catch (e) {
    // Kamera langsung tidak tersedia di browser ini → buka kamera bawaan HP.
    $('#cam-area').innerHTML = '<div class="alert warn small">Kamera akan dibuka melalui aplikasi kamera HP.</div>';
    $('#cam-actions').innerHTML = `
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn accent grow" onclick="$('#cam-file').click()">📸 Buka kamera</button>`;
  }
}

function stopCamera() {
  if (A.stream) { A.stream.getTracks().forEach(t => t.stop()); A.stream = null; }
}

function ambilFoto() {
  const v = $('#cam-video');
  if (!v || !v.videoWidth) return toast('Kamera belum siap, tunggu sebentar.', 'error');
  prosesFoto(v, v.videoWidth, v.videoHeight, true);
  stopCamera();
}

function fotoDariFile(input) {
  const file = input.files && input.files[0];
  if (!file) return;
  if (Date.now() - file.lastModified > 5 * 60 * 1000) {
    return toast('Gunakan foto yang baru diambil dari kamera.', 'error');
  }
  const img = new Image();
  img.onload = () => { prosesFoto(img, img.naturalWidth, img.naturalHeight, false); URL.revokeObjectURL(img.src); };
  img.src = URL.createObjectURL(file);
}

/** Perkecil foto (maks 720 px), beri watermark nama + sesi + waktu server + lokasi, simpan sebagai JPEG. */
function prosesFoto(src, w, h, mirror) {
  const MAX = 720;
  const scale = Math.min(1, MAX / Math.max(w, h));
  const cw = Math.round(w * scale), ch = Math.round(h * scale);
  const c = document.createElement('canvas');
  c.width = cw; c.height = ch;
  const ctx = c.getContext('2d');
  if (mirror) { ctx.translate(cw, 0); ctx.scale(-1, 1); }
  ctx.drawImage(src, 0, 0, cw, ch);
  ctx.setTransform(1, 0, 0, 1, 0, 0);

  const lok = P.status.hari.lokasi;
  const lines = [
    S.user.nama + ' (' + S.user.kode_login + ') | Sesi ' + A.sesi,
    wib(serverNow()).teks,
    lok.nama + ' | ' + A.pos.lat.toFixed(5) + ', ' + A.pos.lng.toFixed(5) + ' (' + A.jarak + ' m)',
  ];
  const fs = Math.max(13, Math.round(cw / 34));
  const pad = Math.round(fs * 0.6);
  const boxH = lines.length * (fs + 4) + pad * 2;
  ctx.fillStyle = 'rgba(0,0,0,0.6)';
  ctx.fillRect(0, ch - boxH, cw, boxH);
  ctx.fillStyle = '#fff';
  ctx.font = '600 ' + fs + 'px system-ui, sans-serif';
  ctx.textBaseline = 'top';
  lines.forEach((t, i) => ctx.fillText(t, pad, ch - boxH + pad + i * (fs + 4), cw - pad * 2));

  A.foto = c.toDataURL('image/jpeg', 0.75);
  $('#cam-area').innerHTML = '<img class="preview-img" src="' + A.foto + '" alt="Foto absen">';
  $('#cam-actions').innerHTML = `
    <button class="btn" onclick="stepFoto()">↻ Ulangi</button>
    <button class="btn primary grow" onclick="stepTtd()">Lanjut</button>`;
}

function stepTtd() {
  openModal(`${stepsHtml(3)}
    <h2>3. Tanda tangan</h2>
    <p class="muted small">Tanda tangani di kotak berikut menggunakan jari.</p>
    <canvas id="sigpad" class="sigpad"></canvas>
    <div class="row mt">
      <button class="btn" onclick="A.pad.clear()">Hapus</button>
      <button class="btn" onclick="stepFoto()">← Foto</button>
      <button class="btn accent grow" id="kirim-btn" onclick="kirimAbsen(this)">Kirim absen</button>
    </div>`);
  A.pad = signaturePad($('#sigpad'));
}

async function kirimAbsen(btn) {
  if (A.pad.isEmpty()) return toast('Tanda tangan masih kosong.', 'error');
  A.ttd = A.pad.toDataURL();
  await busy(btn, async () => {
    const res = await call('absen', { sesi: A.sesi, lat: A.pos.lat, lng: A.pos.lng, akurasi: A.pos.akurasi, foto: A.foto, ttd: A.ttd });
    openModal(`
      <div class="center pad">
        <div style="font-size:48px">✅</div>
        <h2>Absen Sesi ${A.sesi} berhasil</h2>
        <p class="muted">Tercatat pukul <b>${esc(jamSaja(res.waktu))} WIB</b></p>
        <button class="btn primary block" onclick="cacheSet('beranda', null); closeModal(); loadBeranda()">Selesai</button>
      </div>`);
  }, 'Mengirim…');
}

/** Kanvas tanda tangan sederhana (mouse / sentuhan). */
function signaturePad(canvas) {
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  canvas.width = canvas.offsetWidth * ratio;
  canvas.height = canvas.offsetHeight * ratio;
  const ctx = canvas.getContext('2d');
  ctx.scale(ratio, ratio);
  let drawing = false, strokes = 0, last = null;
  const clear = () => {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    strokes = 0;
  };
  clear();
  ctx.strokeStyle = '#0b1f3a'; ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  const pt = e => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
  canvas.addEventListener('pointerdown', e => { drawing = true; last = pt(e); canvas.setPointerCapture(e.pointerId); strokes++; });
  canvas.addEventListener('pointermove', e => {
    if (!drawing) return;
    const p = pt(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p;
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(ev => canvas.addEventListener(ev, () => { drawing = false; }));
  // Diperkecil (lebar maks 600 px) agar file kecil dan ringan dimuat di laporan PDF.
  const toDataURL = () => {
    const scale = Math.min(1, 600 / canvas.width);
    const out = document.createElement('canvas');
    out.width = Math.round(canvas.width * scale);
    out.height = Math.round(canvas.height * scale);
    out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
    return out.toDataURL('image/png');
  };
  return { clear: clear, isEmpty: () => strokes === 0, toDataURL: toDataURL };
}
