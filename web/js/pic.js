// ===================================================== Halaman PIC / Admin
const C = { view: 0, batches: [], batch: null, tab: 'dashboard', hari: [], idHari: null, timer: null, monitor: null, peserta: [], pics: [] };

async function showPic(meRes) {
  C.tab = 'dashboard';
  setTopbar(S.user.nama + (S.user.role === 'admin' ? ' · Admin' : ' · PIC'),
    '<button class="btn sm" onclick="dialogGantiPin()">PIN</button> <button class="btn sm" onclick="logoutNow()">Keluar</button>');
  render('<div class="center muted pad">Memuat…</div>');
  try {
    C.batches = meRes && meRes.batches ? meRes.batches : await call('batchList');
  } catch (e) {
    return render('<div class="alert bad">' + esc(e.message) + '</div>');
  }
  C.hariFor = null;
  const saved = store('lms_pic_batch');
  const tampil = C.batches.filter(b => b.status !== 'arsip');
  C.batch = tampil.find(b => b.id_batch === saved) || tampil[0] || null;
  picTab(C.tab);
}

function pilihBatch(id) {
  C.batch = C.batches.find(b => b.id_batch === id);
  store('lms_pic_batch', id);
  C.idHari = null;
  C.hariFor = null;
  picTab(C.tab);
}

/** Menu PIC/Admin: [kode, ikon, label, grup]. Grup tampil sebagai judul kecil di menu samping (layar lebar). */
const NAV_PIC = [
  ['dashboard', '🏠', 'Dashboard', ''],
  ['monitor', '✅', 'Absensi', 'Kegiatan harian'], ['narasumber', '🎤', 'Absensi Narasumber', 'Kegiatan harian'],
  ['rundown', '🗓️', 'Rundown', 'Kegiatan harian'],
  ['materi', '📚', 'Materi', 'Kegiatan harian'], ['dok', '📷', 'Dokumentasi', 'Kegiatan harian'],
  ['peserta', '👥', 'Peserta', 'Persiapan'], ['lokasi', '📍', 'Jadwal & Lokasi', 'Persiapan'],
  ['kepatuhan', '📘', 'Tata Tertib & Video', 'Persiapan'],
  ['laporan', '📄', 'Laporan', 'Akhir pelatihan'], ['skl', '🎓', 'SKL', 'Akhir pelatihan'],
  ['batch', '🗂️', 'Batch', 'Admin'], ['pustaka', '📚', 'Pustaka Pelatihan', 'Admin'], ['pic', '🔑', 'Akun PIC', 'Admin'],
];
const TAB_BATCH = ['monitor', 'narasumber', 'kepatuhan', 'rundown', 'materi', 'dok', 'laporan', 'skl', 'peserta', 'lokasi'];

function picTab(tab) {
  C.tab = tab;
  C.view++; // hasil muat dari tab sebelumnya yang datang terlambat akan diabaikan
  clearInterval(C.timer);
  const admin = S.user.role === 'admin';
  const nav = NAV_PIC.filter(n => admin || n[3] !== 'Admin');
  const aktif = nav.find(n => n[0] === tab) || nav[0];
  const butuhBatch = TAB_BATCH.indexOf(tab) >= 0;
  // Batch arsip disembunyikan dari pilihan, kecuali sedang dibuka dari tab Batch.
  const pilihan = C.batches.filter(b => b.status !== 'arsip' || (C.batch && b.id_batch === C.batch.id_batch));
  let grup = '';
  render(`
    <div class="shell">
      <nav class="sidenav">
        ${nav.map(n => {
          const judul = n[3] && n[3] !== grup ? `<div class="nav-group">${esc(n[3])}</div>` : '';
          grup = n[3];
          return judul + `<button class="navlink ${tab === n[0] ? 'active' : ''}" onclick="picTab('${n[0]}')">
            <span class="nav-ic">${n[1]}</span><span>${n[2]}</span></button>`;
        }).join('')}
      </nav>
      <section class="shell-main">
        <div class="page-head"><h1>${esc(aktif[2])}</h1></div>
        ${butuhBatch && pilihan.length ? `
          <div class="batch-pilih mb">
            <span class="small muted">Batch</span>
            <select class="grow" onchange="pilihBatch(this.value)">
              ${pilihan.map(b => `<option value="${esc(b.id_batch)}" ${C.batch && b.id_batch === C.batch.id_batch ? 'selected' : ''}>
                ${esc(b.kode_batch)} — ${esc(b.nama_pelatihan)}${b.status === 'arsip' ? ' (arsip)' : b.status !== 'aktif' ? ' (selesai)' : ''}</option>`).join('')}
            </select>
          </div>` : ''}
        <div id="tab-body"><div class="center muted pad">Memuat…</div></div>
      </section>
    </div>`);
  if (butuhBatch && !C.batch) {
    $('#tab-body').innerHTML = '<div class="card center muted">' +
      (admin ? 'Belum ada batch. Buat batch baru di menu <b>Batch</b>.' : 'Anda belum ditugaskan ke batch mana pun. Hubungi Admin.') + '</div>';
    return;
  }
  ({ dashboard: tabDashboard, monitor: tabMonitor, narasumber: tabNarasumber, rundown: tabRundown, materi: tabMateri, dok: tabDok, laporan: tabLaporan, skl: tabSkl,
    peserta: tabPeserta, lokasi: tabLokasi, batch: tabBatch, pic: tabPicAkun, kepatuhan: tabKepatuhan, pustaka: tabPustaka })[aktif[0]]();
}

/** Mengisi isi tab, hanya jika pengguna belum berpindah tab sejak data mulai dimuat. */
function setBody(v, html) { if (v === C.view) $('#tab-body').innerHTML = html; return v === C.view; }
function errBox(v, e) { setBody(v, '<div class="alert bad">' + esc(e.message) + '</div>'); }

/** Daftar hari batch; disimpan selama batch yang sama, kecuali force (mis. setelah lokasi diubah). */
async function muatHari(force) {
  if (force || C.hariFor !== C.batch.id_batch) {
    C.hari = await call('hariList', { id_batch: C.batch.id_batch });
    C.hariFor = C.batch.id_batch;
  }
  if (!C.idHari || !C.hari.some(h => h.id_hari === C.idHari)) {
    const today = wib(serverNow()).tanggal;
    const h = C.hari.find(x => x.tanggal === today) || C.hari.find(x => x.tanggal > today) || C.hari[C.hari.length - 1];
    C.idHari = h ? h.id_hari : null;
  }
}

// ----------------------------------------------------- Tab: Monitor absensi
async function tabMonitor() {
  const v = C.view;
  try {
    if (C.hariFor !== C.batch.id_batch) {
      // Satu permintaan: server memilih hari & mengirim daftar hari sekaligus.
      const m = await call('monitor', { id_batch: C.batch.id_batch, id_hari: C.idHari });
      C.hari = m.hariList; C.hariFor = C.batch.id_batch;
      if (!m.hari) return setBody(v, '<div class="card muted center">Belum ada hari pelatihan.</div>');
      C.idHari = m.hari.id_hari;
      tampilMonitor(v, m);
    } else {
      if (!C.idHari) return setBody(v, '<div class="card muted center">Belum ada hari pelatihan.</div>');
      await muatMonitor(v);
    }
    C.timer = setInterval(() => { if ($('#monitor-table') && $('#modal').classList.contains('hidden')) muatMonitor(v).catch(() => {}); }, 30000);
  } catch (e) { errBox(v, e); }
}

async function muatMonitor(v) {
  if (v === undefined) v = C.view;
  const m = await call('monitor', { id_hari: C.idHari });
  tampilMonitor(v, m);
}

function tampilMonitor(v, m) {
  if (v !== C.view) return;
  syncTime(m.server_now);
  C.monitor = m;
  const STATE = { buka: '🟢 Sedang dibuka', tutup: 'Ditutup', belum_dibuka: 'Belum dibuka' };
  setBody(v, `
    <div class="card">
      <div class="row">
        <select class="grow" onchange="C.idHari=this.value; muatMonitor().catch(e => toast(e.message, 'error'))">
          ${C.hari.map(h => `<option value="${esc(h.id_hari)}" ${h.id_hari === C.idHari ? 'selected' : ''}>Hari ke-${esc(h.hari_ke)} — ${esc(fmtTanggal(h.tanggal))}</option>`).join('')}
        </select>
        <button class="btn" onclick="muatMonitor().catch(e => toast(e.message, 'error'))">↻</button>
      </div>
      <div class="small muted mt">${m.hari.lokasi.bebas ? '🌐 Online — absen bisa dari mana saja' : `📍 ${esc(m.hari.lokasi.nama)} ${m.hari.lokasi.lat === null ? '<span class="badge b-alpa">Lokasi belum diatur</span>' : '(radius ' + esc(m.hari.lokasi.radius) + ' m)'}`}</div>
      <div class="grid2 mt">
        ${m.sesi.map(s => `
          <div>
            <b>Sesi ${s.sesi}</b> <span class="small muted">${esc(s.mulai)}–${esc(s.selesai)} · ${STATE[s.state]}</span>
            <div class="stat mt">
              <div>Hadir <b>${s.rekap.hadir}</b></div><div>Izin <b>${s.rekap.izin}</b></div>
              <div>Sakit <b>${s.rekap.sakit}</b></div><div>Alpa <b>${s.rekap.alpa}</b></div>
              ${s.state === 'buka' ? `<div>Belum <b>${s.rekap.belum}</b></div>` : ''}
            </div>
          </div>`).join('')}
      </div>
    </div>
    <div class="card table-wrap">
      ${m.peserta.length ? `
      <table id="monitor-table">
        <thead><tr><th>No</th><th>Nama</th><th>Sesi 1</th><th>Sesi 2</th></tr></thead>
        <tbody>${m.peserta.map((p, i) => `
          <tr>
            <td>${esc(p.no_absen)}</td>
            <td>${esc(p.nama)}<div class="small muted kode">${esc(p.kode_login)}</div></td>
            ${p.sesi.map((s, j) => `
              <td class="clickable" onclick="detailAbsen(${i}, ${j})">
                ${badge(s.status)} ${s.ada_foto ? '📷' : ''}
                <div class="small muted">${esc(jamSaja(s.waktu))}</div>
              </td>`).join('')}
          </tr>`).join('')}
        </tbody>
      </table>
      <p class="small muted">Klik sel sesi untuk melihat foto & tanda tangan atau mengubah status (izin/sakit/alpa). Data dimuat ulang otomatis tiap 30 detik.</p>`
      : '<div class="muted center">Belum ada peserta. Tambahkan di tab <b>Peserta</b>.</div>'}
    </div>`);
}

async function detailAbsen(i, j) {
  const p = C.monitor.peserta[i], s = p.sesi[j];
  openModal(`
    <h2>${esc(p.nama)} — Sesi ${s.sesi}</h2>
    <div class="small muted">${esc(p.kode_login)} · Hari ke-${esc(C.monitor.hari.hari_ke)} · ${esc(fmtTanggal(C.monitor.hari.tanggal))}</div>
    <div class="mt">${badge(s.status)} ${s.keterangan ? '<span class="small">' + esc(s.keterangan) + '</span>' : ''}</div>
    <div id="detail-foto" class="mt">${s.ada_foto ? '<div class="muted small">Memuat foto…</div>' : ''}</div>
    <h3 class="mt">Ubah status</h3>
    <div class="row">
      ${['izin', 'sakit', 'alpa'].concat(s.ada_foto ? ['hadir'] : []).map(st =>
        `<label class="check" style="margin:0"><input type="radio" name="st" value="${st}" ${s.status === st ? 'checked' : ''}> ${STATUS_LABEL[st]}</label>`).join('')}
    </div>
    <label>Keterangan</label><input id="st-ket" value="${esc(s.keterangan || '')}" placeholder="mis. surat dokter, izin keluarga">
    <div class="row mt">
      <button class="btn" onclick="closeModal()">Tutup</button>
      <button class="btn primary grow" onclick="simpanStatus(this, '${esc(p.id_user)}', ${s.sesi})">Simpan status</button>
    </div>`);
  if (s.ada_foto) {
    try {
      const f = await call('absenFoto', { id_absen: s.id_absen });
      $('#detail-foto').innerHTML = `
        <img class="thumb" src="${f.foto}" alt="Foto absen">
        <div class="small muted mt">Waktu server: <b>${esc(f.waktu)} WIB</b> · ${f.jarak_meter === '' ? 'Online · lokasi bebas' + (f.lat !== '' ? ' (akurasi ± ' + esc(f.akurasi_meter) + ' m)' : ' (GPS tidak tercatat)') : 'Jarak ' + esc(f.jarak_meter) + ' m (akurasi ± ' + esc(f.akurasi_meter) + ' m)'}
          ${f.lat !== '' ? `· <a href="https://www.google.com/maps?q=${esc(f.lat)},${esc(f.lng)}" target="_blank">lihat di peta</a>` : ''}</div>
        <div class="small muted mt">Tanda tangan:</div>
        <img class="thumb" src="${f.ttd}" alt="Tanda tangan" style="max-height:140px">`;
    } catch (e) { $('#detail-foto').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>'; }
  }
}

function simpanStatus(btn, idUser, sesi) {
  const r = $('input[name=st]:checked');
  if (!r) return toast('Pilih status.', 'error');
  busy(btn, async () => {
    await call('absenSetStatus', { id_hari: C.idHari, id_user: idUser, sesi: sesi, status: r.value, keterangan: $('#st-ket').value });
    closeModal();
    toast('Status disimpan.', 'ok');
    await muatMonitor();
  });
}

// ----------------------------------------------------- Tab: Peserta
async function tabPeserta() {
  const v = C.view;
  try {
    C.peserta = await call('pesertaList', { id_batch: C.batch.id_batch });
    const aktif = C.peserta.filter(p => p.aktif).length;
    setBody(v, `
      <div class="card">
        <h2>Tambah peserta</h2>
        <p class="small muted">Satu nama per baris. Bisa langsung tempel (copy–paste) kolom dari Excel:
          <b>Nama</b> · Instansi · No HP · Tempat lahir · Tanggal lahir (dipisah Tab atau titik koma). Kode login dibuat otomatis, mis. <code>001-Kikis</code>.</p>
        <textarea id="peserta-teks" placeholder="Kikis Ramadhan\nAndi Pratama; PT Maju Jaya; 0812xxxx"></textarea>
        <button class="btn primary mt" onclick="tambahPeserta(this)">Tambah peserta</button>
      </div>
      <div class="card">
        <div class="row mb"><h2 class="grow">Daftar peserta (${aktif})</h2>
          ${C.peserta.length ? '<button class="btn sm" onclick="cetakKartu()">🖨 Cetak kartu kode</button>' : ''}</div>
        <div class="table-wrap">
          ${C.peserta.length ? `
          <table>
            <thead><tr><th>No</th><th>Nama</th><th>Kode login</th><th>HP</th><th></th></tr></thead>
            <tbody>${C.peserta.map((p, i) => `
              <tr style="${p.aktif ? '' : 'opacity:.5'}">
                <td>${esc(p.no_absen)}</td>
                <td>${esc(p.nama)}<div class="small muted">${esc(p.instansi)}</div></td>
                <td class="kode">${esc(p.kode_login)}</td>
                <td>${p.hp_terhubung ? '<span class="badge b-hadir">Terhubung</span>' : '<span class="badge b-none">Belum</span>'}</td>
                <td><button class="btn sm" onclick="dialogPeserta(${i})">Kelola</button></td>
              </tr>`).join('')}
            </tbody>
          </table>` : '<div class="muted">Belum ada peserta.</div>'}
        </div>
      </div>`);
  } catch (e) { errBox(v, e); }
}

function tambahPeserta(btn) {
  busy(btn, async () => {
    const added = await call('pesertaTambah', { id_batch: C.batch.id_batch, teks: $('#peserta-teks').value });
    toast(added.length + ' peserta ditambahkan.', 'ok');
    tabPeserta();
  });
}

function dialogPeserta(i) {
  const p = C.peserta[i];
  openModal(`
    <h2>${esc(p.nama)}</h2>
    <div class="small muted">No. absen ${esc(p.no_absen)} · Kode <span class="kode">${esc(p.kode_login)}</span></div>
    <label>Nama lengkap</label><input id="pu-nama" value="${esc(p.nama)}">
    <label>Instansi</label><input id="pu-instansi" value="${esc(p.instansi)}">
    <label>No HP</label><input id="pu-hp" value="${esc(p.no_hp)}">
    <div class="grid2">
      <div><label>Tempat lahir</label><input id="pu-tempat" value="${esc(p.tempat_lahir)}"></div>
      <div><label>Tanggal lahir</label><input id="pu-tgl" type="date" value="${esc(p.tgl_lahir)}"></div>
    </div>
    <p class="small muted">Kode login tidak berubah walaupun nama diubah.</p>
    <button class="btn primary block" onclick="ubahPeserta(this, '${esc(p.id_user)}')">Simpan</button>
    <div class="row mt">
      <button class="btn grow" ${p.hp_terhubung ? '' : 'disabled'} onclick="resetHp(this, '${esc(p.id_user)}')">🔓 Reset HP</button>
      <button class="btn grow danger" onclick="aktifPeserta(this, '${esc(p.id_user)}', ${!p.aktif})">${p.aktif ? 'Nonaktifkan' : 'Aktifkan lagi'}</button>
    </div>
    <p class="small muted">Reset HP dipakai jika peserta ganti HP / browser. Peserta bisa login lagi di perangkat baru.</p>
    <button class="btn block mt" onclick="closeModal()">Tutup</button>`);
}

function ubahPeserta(btn, id) {
  busy(btn, async () => {
    await call('pesertaUbah', { id_user: id, nama: $('#pu-nama').value, instansi: $('#pu-instansi').value, no_hp: $('#pu-hp').value,
      tempat_lahir: $('#pu-tempat').value, tgl_lahir: $('#pu-tgl').value });
    closeModal(); toast('Data peserta disimpan.', 'ok'); tabPeserta();
  });
}
function resetHp(btn, id) {
  busy(btn, async () => { await call('pesertaResetDevice', { id_user: id }); closeModal(); toast('HP peserta di-reset.', 'ok'); tabPeserta(); });
}
function aktifPeserta(btn, id, aktif) {
  if (!aktif && !confirm('Nonaktifkan peserta ini? Peserta tidak bisa login dan tidak muncul di absensi.')) return;
  busy(btn, async () => { await call('pesertaAktif', { id_user: id, aktif: aktif }); closeModal(); tabPeserta(); });
}

function cetakKartu() {
  const b = C.batch;
  $('#print-area').innerHTML = `
    <h3 style="margin:0 0 8px">${esc(b.kode_batch)} — ${esc(b.nama_pelatihan)}</h3>
    <div class="cards-print">${C.peserta.filter(p => p.aktif).map(p => `
      <div class="kartu">
        <b>LMS Artha Safety Indonesia</b><br>${esc(p.nama)}
        <span class="kode">${esc(p.kode_login)}</span>
        Batch: ${esc(b.kode_batch)}<br>Login di link LMS, pilih batch, lalu ketik kode di atas.
      </div>`).join('')}
    </div>`;
  window.print();
}

// ----------------------------------------------------- Tab: Jadwal & lokasi
async function tabLokasi() {
  const v = C.view;
  try {
    await muatHari(true);
    const b = C.batch;
    setBody(v, `
      <div class="card">
        <h2>Jam sesi absen</h2>
        <div class="grid2">
          <div><b>Sesi 1</b><div class="row"><input type="time" id="s1m" value="${esc(b.sesi1_mulai)}" class="grow"> – <input type="time" id="s1s" value="${esc(b.sesi1_selesai)}" class="grow"></div></div>
          <div><b>Sesi 2</b><div class="row"><input type="time" id="s2m" value="${esc(b.sesi2_mulai)}" class="grow"> – <input type="time" id="s2s" value="${esc(b.sesi2_selesai)}" class="grow"></div></div>
        </div>
        <button class="btn primary mt" onclick="simpanJam(this)">Simpan jam sesi</button>
      </div>
      <div class="card">
        <h2>Aturan lokasi absen</h2>
        <p class="small muted">Pelatihan <b>offline</b> wajib absen di lokasi pelatihan (dalam radius). Pelatihan <b>online</b> boleh absen dari mana saja.
          Untuk batch <b>blended</b>, pilih "Wajib di lokasi" lalu tandai hari-hari online di tabel <i>Lokasi per hari</i>.</p>
        <div class="seg">
          <button type="button" class="${b.lokasi_bebas === 'ya' ? '' : 'active'}" onclick="simpanAturanBatch(this, '')">📍 Wajib di lokasi (offline)</button>
          <button type="button" class="${b.lokasi_bebas === 'ya' ? 'active' : ''}" onclick="simpanAturanBatch(this, 'ya')">🌐 Bebas dari mana saja (online)</button>
        </div>
      </div>
      <div class="card">
        <h2>Lokasi default batch</h2>
        <p class="small muted">Dipakai untuk semua hari, kecuali hari yang diberi lokasi khusus di bawah.</p>
        ${lokasiForm('def', b.lokasi_nama, b.lat, b.lng, b.radius_meter)}
        <button class="btn primary mt" onclick="simpanLokasiDefault(this)">Simpan lokasi default</button>
      </div>
      <div class="card table-wrap">
        <h2>Lokasi per hari</h2>
        <table>
          <thead><tr><th>Hari</th><th>Aturan</th><th>Lokasi</th><th></th></tr></thead>
          <tbody>${C.hari.map((h, i) => `
            <tr>
              <td><b>Hari ke-${esc(h.hari_ke)}</b><div class="small muted">${esc(fmtTanggal(h.tanggal))}</div></td>
              <td><select onchange="simpanAturanHari(this, '${esc(h.id_hari)}')">
                  <option value="" ${h.lokasi.aturan_hari === '' ? 'selected' : ''}>Ikut batch (${b.lokasi_bebas === 'ya' ? 'online' : 'wajib lokasi'})</option>
                  <option value="tidak" ${h.lokasi.aturan_hari === 'tidak' ? 'selected' : ''}>📍 Wajib di lokasi</option>
                  <option value="ya" ${h.lokasi.aturan_hari === 'ya' ? 'selected' : ''}>🌐 Online (bebas)</option>
                </select></td>
              <td>${h.lokasi.bebas ? '<span class="badge b-izin">🌐 Online — tanpa cek lokasi</span>'
                : `${h.lokasi.khusus ? '📍 <b>' + esc(h.lokasi.nama) + '</b>' : '<span class="muted">(default) ' + esc(h.lokasi.nama) + '</span>'}
                <div class="small muted">${h.lokasi.lat === null ? 'belum diatur' : 'radius ' + esc(h.lokasi.radius) + ' m'}</div>`}</td>
              <td class="row" style="justify-content:flex-end">
                <button class="btn sm" onclick="dialogLokasiHari(${i})">Ubah</button>
                ${h.lokasi.khusus ? `<button class="btn sm" title="Kembalikan ke default" onclick="resetLokasiHari(this, '${esc(h.id_hari)}')">↺</button>` : ''}
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`);
  } catch (e) { errBox(v, e); }
}

/** Form lokasi: nama, koordinat (dari GPS PIC / link Maps / ketik), radius. */
function lokasiForm(p, nama, lat, lng, radius) {
  return `
    <label>Nama lokasi</label><input id="${p}-nama" value="${esc(nama || '')}" placeholder="mis. Kantor ASI Jakarta / Site PT XYZ">
    <div class="row mt">
      <button type="button" class="btn grow" onclick="pakaiLokasiSaya(this, '${p}')">📍 Pakai lokasi saya sekarang</button>
    </div>
    <label>…atau tempel link Google Maps / koordinat</label>
    <div class="row"><input id="${p}-teks" class="grow" placeholder="https://maps.app.goo.gl/… atau -6.2088, 106.8456">
      <button type="button" class="btn" onclick="ambilDariTeks(this, '${p}')">Ambil</button></div>
    <div class="grid2">
      <div><label>Latitude</label><input id="${p}-lat" value="${esc(lat)}" inputmode="decimal"></div>
      <div><label>Longitude</label><input id="${p}-lng" value="${esc(lng)}" inputmode="decimal"></div>
    </div>
    <label>Radius absen (meter)</label><input id="${p}-radius" type="number" min="20" max="5000" value="${esc(radius || 150)}">
    <div id="${p}-cek" class="small mt"></div>`;
}

function lokasiValue(p) {
  return { lokasi_nama: $('#' + p + '-nama').value.trim(), lat: $('#' + p + '-lat').value.trim(), lng: $('#' + p + '-lng').value.trim(), radius_meter: $('#' + p + '-radius').value };
}

function isiKoordinat(p, lat, lng, info) {
  $('#' + p + '-lat').value = Number(lat).toFixed(6);
  $('#' + p + '-lng').value = Number(lng).toFixed(6);
  $('#' + p + '-cek').innerHTML = '<span class="badge b-hadir">✓</span> ' + esc(info) +
    ` · <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank">cek di peta</a>`;
}

function pakaiLokasiSaya(btn, p) {
  busy(btn, async () => {
    const pos = await getPosition();
    isiKoordinat(p, pos.lat, pos.lng, 'Lokasi Anda (akurasi ± ' + Math.round(pos.akurasi) + ' m)');
  }, 'Membaca GPS…');
}

function ambilDariTeks(btn, p) {
  busy(btn, async () => {
    const r = await call('lokasiDariTeks', { teks: $('#' + p + '-teks').value });
    isiKoordinat(p, r.lat, r.lng, 'Koordinat ditemukan');
  });
}

async function perbaruiBatch(btn, perubahan, pesan) {
  await busy(btn, async () => {
    const b = await call('batchUbah', { id_batch: C.batch.id_batch, batch: perubahan });
    const i = C.batches.findIndex(x => x.id_batch === b.id_batch);
    C.batches[i] = b; C.batch = b;
    toast(pesan, 'ok');
    tabLokasi();
  });
}

function simpanJam(btn) {
  perbaruiBatch(btn, { sesi1_mulai: $('#s1m').value, sesi1_selesai: $('#s1s').value, sesi2_mulai: $('#s2m').value, sesi2_selesai: $('#s2s').value }, 'Jam sesi disimpan.');
}

function simpanLokasiDefault(btn) {
  const v = lokasiValue('def');
  if (!v.lat || !v.lng) return toast('Isi koordinat lokasi terlebih dahulu.', 'error');
  perbaruiBatch(btn, v, 'Lokasi default disimpan.');
}

function simpanAturanBatch(btn, nilai) {
  if (nilai === 'ya' && !confirm('Jadikan batch ini BEBAS LOKASI (online)?\n\nPeserta bisa absen dari mana saja, kecuali hari yang ditandai "Wajib di lokasi". Absensi yang sudah masuk tidak berubah.')) return;
  perbaruiBatch(btn, { lokasi_bebas: nilai }, nilai === 'ya' ? 'Batch: absen bebas dari mana saja.' : 'Batch: absen wajib di lokasi.');
}

function simpanAturanHari(sel, idHari) {
  busy(null, async () => {
    await call('hariSetAturan', { id_hari: idHari, aturan: sel.value });
    toast('Aturan lokasi hari disimpan.', 'ok');
    tabLokasi();
  });
}

function dialogLokasiHari(i) {
  const h = C.hari[i], l = h.lokasi;
  openModal(`
    <h2>Lokasi Hari ke-${esc(h.hari_ke)}</h2>
    <div class="small muted">${esc(fmtTanggal(h.tanggal))}</div>
    ${lokasiForm('hari', l.khusus ? l.nama : '', l.khusus ? l.lat : '', l.khusus ? l.lng : '', l.radius)}
    <div class="row mt">
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn primary grow" onclick="simpanLokasiHari(this, '${esc(h.id_hari)}')">Simpan lokasi hari ini</button>
    </div>`);
}

function simpanLokasiHari(btn, idHari) {
  const v = lokasiValue('hari');
  if (!v.lat || !v.lng) return toast('Isi koordinat lokasi terlebih dahulu.', 'error');
  busy(btn, async () => {
    await call('hariSetLokasi', Object.assign({ id_hari: idHari }, v));
    closeModal(); toast('Lokasi hari disimpan.', 'ok'); tabLokasi();
  });
}

function resetLokasiHari(btn, idHari) {
  busy(btn, async () => { await call('hariResetLokasi', { id_hari: idHari }); toast('Kembali ke lokasi default.', 'ok'); tabLokasi(); });
}

// ----------------------------------------------------- Tab: Batch (Admin)
async function tabBatch() {
  const v = C.view;
  try {
    [C.pics, C.batches, C.programs] = await Promise.all([call('picList'), call('batchList'), call('programList')]);
    C.tc = { nb: [] };
    setBody(v, `
      <div class="card">
        <h2>Buat batch baru</h2>
        <form onsubmit="event.preventDefault(); buatBatch(this)">
          <div class="grid2">
            <div><label>Nama pelatihan</label><input name="nama_pelatihan" required placeholder="mis. Ahli K3 Umum"></div>
            <div><label>Kode batch</label><input name="kode_batch" required placeholder="mis. B07"></div>
          </div>
          ${programSkemaHtml({})}
          ${formTanggalHtml('nb', {})}
          <label>PIC batch (pilih 2)</label>
          ${picCheckboxes([])}
          <p class="small muted">Jam sesi default 08:00–12:00 dan 13:00–17:00. Lokasi diatur di tab <b>Jadwal & Lokasi</b> setelah batch dibuat.</p>
          <button class="btn primary">Buat batch</button>
        </form>
      </div>
      <div class="card table-wrap">
        <h2>Semua batch</h2>
        ${C.batches.some(b => b.status !== 'arsip') ? `<table>
          <thead><tr><th>Batch</th><th>Tanggal</th><th>PIC</th><th>Peserta</th><th></th></tr></thead>
          <tbody>${C.batches.map((b, i) => b.status === 'arsip' ? '' : `
            <tr>
              <td><b>${esc(b.kode_batch)}</b> ${b.status === 'aktif' ? '<span class="badge b-hadir">aktif</span>' : '<span class="badge b-none">selesai</span>'}
                <div class="small">${esc(b.nama_pelatihan)}</div>
                <div class="small muted">${b.id_program ? '📚 ' + esc(namaProgram(b.id_program)) : '<span class="badge b-belum">program belum dipilih</span>'}${b.jenis_pelatihan ? ' · ' + esc(b.jenis_pelatihan) : ''}</div></td>
              <td class="small">${esc(b.tgl_mulai)}<br>${esc(b.tgl_selesai)}${b.tanggal_custom ? '<div class="muted">' + b.tanggal_custom.split(',').length + ' tanggal pilihan</div>' : ''}</td>
              <td class="small">${esc(b.pic_nama.join(', ') || '—')}</td>
              <td>${esc(b.jumlah_peserta)}</td>
              <td class="row"><button class="btn sm" onclick="dialogBatch(${i})">Ubah</button>
                ${b.status === 'selesai' || b.tgl_selesai < wib(serverNow()).tanggal
                  ? `<button class="btn sm" onclick="arsipBatch(this, ${i}, true)">🗄 Arsipkan</button>` : ''}
                ${b.folder_url ? `<a class="btn sm" href="${esc(b.folder_url)}" target="_blank">Drive</a>` : ''}
                <button class="btn sm" onclick="dialogDuplikat(${i})">⧉ Duplikat</button>
                <button class="btn sm danger" onclick="dialogHapusBatch(${i})">🗑 Hapus</button></td>
            </tr>`).join('')}
          </tbody></table>` : '<div class="muted">Belum ada batch.</div>'}
      </div>
      ${C.batches.some(b => b.status === 'arsip') ? `
      <div class="card table-wrap">
        <h2>🗄 Arsip pelatihan</h2>
        <p class="small muted">Batch arsip disembunyikan dari pilihan batch dan Dashboard. Datanya tetap tersimpan dan bisa dibuka atau dipulihkan.</p>
        <table>
          <thead><tr><th>Batch</th><th>Tanggal</th><th>Peserta</th><th></th></tr></thead>
          <tbody>${C.batches.map((b, i) => b.status !== 'arsip' ? '' : `
            <tr>
              <td><b>${esc(b.kode_batch)}</b> <span class="badge b-none">arsip</span><div class="small">${esc(b.nama_pelatihan)}</div></td>
              <td class="small">${esc(b.tgl_mulai)}<br>${esc(b.tgl_selesai)}</td>
              <td>${esc(b.jumlah_peserta)}</td>
              <td class="row"><button class="btn sm" onclick="bukaArsip('${esc(b.id_batch)}')">Lihat data</button>
                <button class="btn sm" onclick="arsipBatch(this, ${i}, false)">↩ Pulihkan</button>
                <button class="btn sm" onclick="dialogDuplikat(${i})">⧉ Duplikat</button>
                <button class="btn sm danger" onclick="dialogHapusBatch(${i})">🗑 Hapus</button></td>
            </tr>`).join('')}
          </tbody></table>
      </div>` : ''}`);
  } catch (e) { errBox(v, e); }
}

function picCheckboxes(selected) {
  const list = C.pics.filter(p => p.aktif !== 'tidak');
  if (!list.length) return '<div class="small muted">Belum ada akun PIC. Tambahkan di tab <b>Akun PIC</b>.</div>';
  return list.map(p => `<label class="check"><input type="checkbox" name="pic" value="${esc(p.id_user)}" ${selected.indexOf(p.id_user) >= 0 ? 'checked' : ''}>
    ${esc(p.nama)} <span class="small muted">(${esc(p.kode_login)}${p.role === 'admin' ? ', admin' : ''})</span></label>`).join('');
}

function formPicIds(form) { return $$('input[name=pic]:checked', form).map(x => x.value); }

function buatBatch(form) {
  const tgl = tanggalForm(form, 'nb');
  if (!tgl) return;
  busy(form.querySelector('button.primary'), async () => {
    const b = await call('batchBuat', { batch: Object.assign({
      nama_pelatihan: form.nama_pelatihan.value, kode_batch: form.kode_batch.value,
      pic_ids: formPicIds(form), id_program: form.id_program.value, jenis_pelatihan: form.jenis_pelatihan.value,
    }, tgl) });
    toast('Batch ' + b.kode_batch + ' dibuat.', 'ok');
    C.batches = await call('batchList');
    C.tab = 'lokasi';
    pilihBatch(b.id_batch);
  });
}

async function dialogBatch(i) {
  const b = C.batches[i];
  if (!C.programs) C.programs = await call('programList').catch(() => []);
  C.tc = Object.assign(C.tc || {}, { ub: b.tanggal_custom ? b.tanggal_custom.split(',') : [] });
  openModal(`
    <h2>Ubah batch ${esc(b.kode_batch)}</h2>
    <form id="form-batch" onsubmit="event.preventDefault(); simpanBatch(this, '${esc(b.id_batch)}')">
      <label>Nama pelatihan</label><input name="nama_pelatihan" value="${esc(b.nama_pelatihan)}" required>
      ${programSkemaHtml(b)}
      ${formTanggalHtml('ub', b)}
      <label>Status</label>
      <select name="status"><option value="aktif" ${b.status === 'aktif' ? 'selected' : ''}>Aktif</option>
        <option value="selesai" ${b.status === 'selesai' ? 'selected' : ''}>Selesai (peserta tidak bisa login)</option>
        <option value="arsip" ${b.status === 'arsip' ? 'selected' : ''}>Diarsipkan (disembunyikan dari daftar)</option></select>
      <label>PIC batch</label>
      ${picCheckboxes(b.pic_ids ? b.pic_ids.split(',') : [])}
      <p class="small muted">Jika tanggal diubah, hari yang sudah berisi absensi tidak akan dihapus.</p>
      <div class="row mt"><button type="button" class="btn" onclick="closeModal()">Batal</button>
        <button class="btn primary grow">Simpan</button></div>
    </form>`);
}

function simpanBatch(form, id) {
  const tgl = tanggalForm(form, 'ub');
  if (!tgl) return;
  busy(form.querySelector('button.primary'), async () => {
    await call('batchUbah', { id_batch: id, batch: Object.assign({
      nama_pelatihan: form.nama_pelatihan.value, status: form.status.value, pic_ids: formPicIds(form),
      id_program: form.id_program.value, jenis_pelatihan: form.jenis_pelatihan.value,
    }, tgl) });
    closeModal(); toast('Batch disimpan.', 'ok');
    C.hariFor = null;
    C.batches = await call('batchList');
    C.batch = C.batches.find(x => x.id_batch === (C.batch && C.batch.id_batch)) || C.batches[0] || null;
    picTab('batch');
  });
}

/** Arsipkan (arsip = true) atau pulihkan batch. Peserta dengan SKL terbit tetap bisa mengunduh SKL. */
function arsipBatch(btn, i, arsip) {
  const b = C.batches[i];
  if (arsip && !confirm('Arsipkan batch ' + b.kode_batch + '?\n\nBatch disembunyikan dari pilihan batch dan Dashboard, peserta tidak bisa login ' +
    '(kecuali untuk mengunduh SKL yang sudah terbit). Data tetap tersimpan dan bisa dipulihkan.')) return;
  busy(btn, async () => {
    await call('batchUbah', { id_batch: b.id_batch, batch: { status: arsip ? 'arsip' : 'selesai' } });
    toast(arsip ? 'Batch ' + b.kode_batch + ' diarsipkan.' : 'Batch ' + b.kode_batch + ' dipulihkan (status Selesai).', 'ok');
    C.batches = await call('batchList');
    if (arsip && C.batch && C.batch.id_batch === b.id_batch) C.batch = C.batches.find(x => x.status !== 'arsip') || null;
    picTab('batch');
  });
}

/** Duplikat batch: rundown & materi ikut, peserta/absensi/lokasi tidak. */
async function dialogDuplikat(i) {
  const b = C.batches[i];
  let info;
  try {
    [info, C.programs] = await Promise.all([call('duplikatInfo', { id_batch: b.id_batch }), C.programs ? C.programs : call('programList')]);
  } catch (e) { return toast(e.message, 'error'); }
  if (!C.pics || !C.pics.length) C.pics = await call('picList').catch(() => []);
  C.tc = Object.assign(C.tc || {}, { db: [] });
  openModal(`
    <h2>Duplikat batch ${esc(b.kode_batch)}</h2>
    <div class="alert info small">Batch sumber: <b>${info.hari} hari</b>, ${info.rundown} kegiatan rundown, ${info.materi} materi${info.materi_mb ? ' (file ' + info.materi_mb + ' MB)' : ''}. Disalin per <b>Hari ke-N</b>: rundown & materi Hari ke-1 sumber → Hari ke-1 batch baru, dst.</div>
    <form onsubmit="event.preventDefault(); duplikatBatch(this, ${i})">
      <div class="grid2">
        <div><label>Nama pelatihan</label><input name="nama_pelatihan" value="${esc(b.nama_pelatihan)}" required></div>
        <div><label>Kode batch baru</label><input name="kode_batch" required placeholder="mis. ${esc(b.kode_batch)}-2"></div>
      </div>
      ${programSkemaHtml(b)}
      ${formTanggalHtml('db', {})}
      <p class="small muted">Pilih <b>${info.hari} hari</b> pelatihan agar semua rundown & materi tersalin.</p>
      <label>PIC batch</label>
      ${picCheckboxes([])}
      <label>Yang disalin</label>
      <label class="check"><input type="checkbox" name="rundown" checked> Rundown (disimpan sebagai <i>draft</i>, terbitkan setelah diperiksa)</label>
      <label class="check" style="margin-left:24px"><input type="checkbox" name="kosongkan_pemateri" checked> Kosongkan nama narasumber & instansi (diisi ulang untuk batch baru)</label>
      <label class="check"><input type="checkbox" name="materi" checked> Materi (file ikut disalin)</label>
      <p class="small muted">Tidak disalin: peserta, absensi, dokumentasi, laporan, SKL, absensi narasumber, dan lokasi. Jam sesi & data laporan resmi ikut disalin
        (kecuali nomor permohonan, jumlah pendaftar/lulus, dan kode SKL).</p>
      <div class="row mt"><button type="button" class="btn" onclick="closeModal()">Batal</button>
        <button class="btn primary grow">⧉ Duplikat batch</button></div>
    </form>`);
}

function duplikatBatch(form, i) {
  const src = C.batches[i];
  const tgl = tanggalForm(form, 'db');
  if (!tgl) return;
  busy(form.querySelector('button.primary'), async () => {
    const res = await call('batchDuplikat', { id_batch: src.id_batch,
      batch: Object.assign({ nama_pelatihan: form.nama_pelatihan.value, kode_batch: form.kode_batch.value, pic_ids: formPicIds(form),
        id_program: form.id_program.value, jenis_pelatihan: form.jenis_pelatihan.value }, tgl),
      opsi: { rundown: form.rundown.checked, kosongkan_pemateri: form.kosongkan_pemateri.checked, materi: form.materi.checked } });
    closeModal();
    const h = res.hasil;
    toast('Batch ' + res.batch.kode_batch + ' dibuat: ' + h.rundown + ' kegiatan rundown & ' + h.materi + ' materi disalin.' +
      (h.hari_dilewati ? ' ' + h.hari_dilewati + ' hari terakhir sumber tidak tersalin karena batch baru lebih sedikit harinya.' : ''), h.hari_dilewati ? 'error' : 'ok');
    C.batches = await call('batchList');
    C.tab = 'lokasi';
    pilihBatch(res.batch.id_batch);
  }, 'Menduplikat…');
}

/** Hapus permanen batch (mis. batch percobaan): wajib mengetik ulang kode batch. */
function dialogHapusBatch(i) {
  const b = C.batches[i];
  openModal(`
    <h2>Hapus batch ${esc(b.kode_batch)}?</h2>
    <div class="alert bad small"><b>Tidak bisa dibatalkan.</b> Semua data batch <b>${esc(b.kode_batch)} — ${esc(b.nama_pelatihan)}</b> akan dihapus permanen:
      ${esc(b.jumlah_peserta)} peserta beserta absensi, foto & tanda tangannya, rundown, materi, dokumentasi, laporan PDF, SKL, dan absensi narasumber.</div>
    <p class="small muted">Untuk pelatihan sungguhan yang sudah selesai, gunakan <b>🗄 Arsipkan</b> agar datanya tetap tersimpan.
      Hapus hanya untuk batch percobaan/dummy.</p>
    <label>Ketik kode batch <b>${esc(b.kode_batch)}</b> untuk konfirmasi</label>
    <input id="hapus-kode" autocomplete="off" placeholder="${esc(b.kode_batch)}">
    <div class="row mt"><button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn primary grow" style="background:var(--bad);border-color:var(--bad)" onclick="hapusBatch(this, ${i})">🗑 Hapus permanen</button></div>`);
}

function hapusBatch(btn, i) {
  const b = C.batches[i];
  const kode = $('#hapus-kode').value.trim();
  if (kode !== b.kode_batch) return toast('Kode batch tidak sesuai. Ketik: ' + b.kode_batch, 'error');
  busy(btn, async () => {
    await call('batchHapus', { id_batch: b.id_batch, konfirmasi: kode });
    closeModal(); toast('Batch ' + b.kode_batch + ' dihapus.', 'ok');
    C.batches = await call('batchList');
    if (C.batch && C.batch.id_batch === b.id_batch) {
      C.batch = C.batches.find(x => x.status !== 'arsip') || null;
      C.idHari = null; C.hariFor = null;
    }
    picTab('batch');
  }, 'Menghapus…');
}

/** Membuka data batch arsip (absensi, laporan, SKL, dst.) tanpa memulihkannya. */
function bukaArsip(id) {
  C.batch = C.batches.find(b => b.id_batch === id);
  C.idHari = null;
  C.hariFor = null;
  picTab('laporan');
}

// ----------------------------------------------------- Tab: Akun PIC (Admin)
async function tabPicAkun() {
  const v = C.view;
  try {
    [C.pics, C.batches] = await Promise.all([call('picList'), call('batchList')]);
    setBody(v, `
      <div class="card">
        <h2>Tambah akun PIC</h2>
        <form onsubmit="event.preventDefault(); tambahPic(this)">
          <div class="grid2">
            <div><label>Nama</label><input name="nama" required></div>
            <div><label>No HP</label><input name="no_hp"></div>
            <div><label>Kode login</label><input name="kode" required placeholder="mis. PIC-Dina"></div>
            <div><label>PIN (4–8 angka)</label><input name="pin" inputmode="numeric" required></div>
          </div>
          <button class="btn primary mt">Tambah PIC</button>
        </form>
      </div>
      <div class="card table-wrap">
        <h2>Akun PIC & Admin</h2>
        <table><thead><tr><th>Nama</th><th>Kode</th><th>Peran</th><th></th></tr></thead>
          <tbody>${C.pics.map((p, i) => `
            <tr><td>${esc(p.nama)} ${p.aktif === 'tidak' ? '<span class="badge b-alpa">nonaktif</span>' : ''}
                <div class="small muted">${p.no_hp && p.no_hp !== '0' ? esc(p.no_hp) : ''}</div></td>
              <td class="kode">${esc(p.kode_login)}</td><td>${p.role === 'admin' ? 'Admin' : 'PIC'}</td>
              <td class="row"><button class="btn sm" onclick="dialogPic(${i})">✏️ Edit</button>
                <button class="btn sm" onclick="resetPinPic(this, '${esc(p.id_user)}', '${esc(p.nama)}')">Reset PIN</button></td></tr>`).join('')}
          </tbody></table>
      </div>`);
  } catch (e) { errBox(v, e); }
}

function dialogPic(i) {
  const p = C.pics[i];
  const saya = p.id_user === S.user.id_user;
  const batches = C.batches.filter(b => b.status !== 'arsip');
  openModal(`
    <h2>Edit akun ${esc(p.nama)}</h2>
    <form onsubmit="event.preventDefault(); simpanPic(this, '${esc(p.id_user)}')">
      <label>Nama</label><input name="nama" value="${esc(p.nama)}" required>
      <div class="grid2">
        <div><label>Kode login</label><input name="kode" value="${esc(p.kode_login)}" required></div>
        <div><label>No HP</label><input name="no_hp" value="${esc(p.no_hp === '0' ? '' : p.no_hp)}"></div>
      </div>
      <label>Hak akses</label>
      <select name="role" ${saya ? 'disabled' : ''}>
        <option value="pic" ${p.role !== 'admin' ? 'selected' : ''}>PIC — mengelola batch yang ditugaskan</option>
        <option value="admin" ${p.role === 'admin' ? 'selected' : ''}>Admin — semua batch, akun PIC, data perusahaan</option>
      </select>
      <label class="check mt"><input type="checkbox" name="aktif" ${p.aktif !== 'tidak' ? 'checked' : ''} ${saya ? 'disabled' : ''}>
        Akun aktif (bisa login)</label>
      <label>Batch yang ditangani</label>
      ${batches.length ? batches.map(b => `<label class="check"><input type="checkbox" name="batch" value="${esc(b.id_batch)}"
          ${(b.pic_ids || '').split(',').indexOf(p.id_user) >= 0 ? 'checked' : ''}>
          ${esc(b.kode_batch)} <span class="small muted">— ${esc(b.nama_pelatihan)}</span></label>`).join('')
        : '<div class="small muted">Belum ada batch.</div>'}
      <p class="small muted">${saya ? 'Peran dan status akun Anda sendiri tidak bisa diubah di sini. ' : ''}Admin otomatis bisa membuka semua batch.
        Akun nonaktif langsung keluar dari semua perangkat. PIN diubah lewat tombol <b>Reset PIN</b>.</p>
      <div class="row mt"><button type="button" class="btn" onclick="closeModal()">Batal</button>
        <button class="btn primary grow">Simpan</button></div>
    </form>`);
}

function simpanPic(form, id) {
  busy(form.querySelector('button.primary'), async () => {
    const p = C.pics.find(x => x.id_user === id);
    await call('picUbah', { id_user: id, nama: form.nama.value, kode: form.kode.value, no_hp: form.no_hp.value,
      role: form.role.disabled ? p.role : form.role.value, aktif: form.aktif.disabled ? true : form.aktif.checked,
      batch_ids: $$('input[name=batch]:checked', form).map(x => x.value) });
    closeModal(); toast('Akun ' + form.nama.value + ' disimpan.', 'ok');
    if (id === S.user.id_user) { S.user.nama = form.nama.value; }
    C.batches = await call('batchList');
    tabPicAkun();
  });
}

function tambahPic(form) {
  busy(form.querySelector('button'), async () => {
    await call('picTambah', { nama: form.nama.value, kode: form.kode.value, pin: form.pin.value, no_hp: form.no_hp.value });
    toast('Akun PIC ditambahkan.', 'ok');
    tabPicAkun();
  });
}

function resetPinPic(btn, id, nama) {
  const pin = prompt('PIN baru untuk ' + nama + ' (4–8 angka):');
  if (!pin) return;
  busy(btn, async () => { await call('picResetPin', { id_user: id, pin: pin }); toast('PIN di-reset.', 'ok'); });
}

function dialogGantiPin() {
  openModal(`
    <h2>Ganti PIN</h2>
    <form onsubmit="event.preventDefault(); gantiPinSaya(this)">
      <label>PIN lama</label><input name="lama" type="password" inputmode="numeric" required>
      <label>PIN baru (4–8 angka)</label><input name="baru" type="password" inputmode="numeric" required>
      <div class="row mt"><button type="button" class="btn" onclick="closeModal()">Batal</button>
        <button class="btn primary grow">Simpan</button></div>
    </form>`);
}

function gantiPinSaya(form) {
  busy(form.querySelector('button.primary'), async () => {
    await call('gantiPin', { pin_lama: form.lama.value, pin_baru: form.baru.value });
    closeModal(); toast('PIN diganti.', 'ok');
  });
}

// ----------------------------------------------------- Pilih hari (dipakai tab Rundown, Materi, Dokumentasi)
function hariSelect(fn) {
  return `<select class="grow" onchange="C.idHari=this.value; ${fn ? fn + '()' : ''}">
    ${C.hari.map(h => `<option value="${esc(h.id_hari)}" ${h.id_hari === C.idHari ? 'selected' : ''}>Hari ke-${esc(h.hari_ke)} — ${esc(fmtTanggal(h.tanggal))}</option>`).join('')}
  </select>`;
}

function hariAktif() { return C.hari.find(h => h.id_hari === C.idHari); }

// ----------------------------------------------------- Tab: Rundown
async function tabRundown() {
  const v = C.view;
  try {
    await muatHari();
    if (!C.idHari) return setBody(v, '<div class="card muted center">Belum ada hari pelatihan.</div>');
    const rd = await call('rundownGet', { id_hari: C.idHari });
    if (v !== C.view) return;
    C.rd = rd.items.length ? rd.items : [{}];
    const STATUS = { terbit: '<span class="badge b-hadir">Terbit — tampil di akun peserta</span>',
      draft: '<span class="badge b-belum">Draft — belum tampil ke peserta</span>', kosong: '<span class="badge b-none">Belum ada rundown</span>' };
    const lain = C.hari.filter(h => h.id_hari !== C.idHari);
    setBody(v, `
      <div class="card">
        <div class="row">${hariSelect('tabRundown')}</div>
        <div class="mt">${STATUS[rd.status]}</div>
      </div>
      <div class="card">
        <h2>Susun rundown</h2>
        <p class="small muted">Urutan otomatis mengikuti jam mulai saat disimpan.</p>
        <div id="rd-rows"></div>
        <button class="btn mt" onclick="rdTambah()">＋ Tambah kegiatan</button>
        <div class="row mt">
          <button class="btn grow" onclick="rdSimpan(this, 'draft')">Simpan sebagai draft</button>
          <button class="btn accent grow" onclick="rdSimpan(this, 'terbit')">Simpan & terbitkan</button>
        </div>
      </div>
      ${lain.length ? `
      <div class="card">
        <h3>Salin dari hari lain</h3>
        <div class="row">
          <select id="rd-asal" class="grow">${lain.map(h => `<option value="${esc(h.id_hari)}">Hari ke-${esc(h.hari_ke)} — ${esc(fmtTanggal(h.tanggal))}</option>`).join('')}</select>
          <button class="btn" onclick="rdSalin(this)">Salin</button>
        </div>
        <p class="small muted">Rundown hari ini akan diganti dengan salinan (sebagai draft), lalu bisa diedit.</p>
      </div>` : ''}`);
    rdRender();
  } catch (e) { errBox(v, e); }
}

function rdRender() {
  $('#rd-rows').innerHTML = C.rd.map((r, i) => `
    <div class="rd-item" data-i="${i}">
      <div class="rd-head">
        <span class="rd-no">${i + 1}</span>
        <input type="time" class="rd-f" data-k="jam_mulai" value="${esc(r.jam_mulai || '')}" aria-label="Jam mulai">
        <span>–</span>
        <input type="time" class="rd-f" data-k="jam_selesai" value="${esc(r.jam_selesai || '')}" aria-label="Jam selesai">
        <button class="btn sm danger" title="Hapus baris" onclick="rdHapus(${i})">✕</button>
      </div>
      <input class="rd-f mt" data-k="kegiatan" value="${esc(r.kegiatan || '')}" placeholder="Kegiatan, mis. Pembukaan / Materi K3 Listrik">
      <div class="grid2" style="gap:6px;margin-top:6px">
        <input class="rd-f" data-k="pemateri" value="${esc(r.pemateri || '')}" placeholder="Pemateri / trainer (opsional)">
        <input class="rd-f" data-k="instansi" value="${esc(r.instansi || '')}" placeholder="Instansi pemateri, mis. Disnakertrans Prov. DIY / Praktisi K3">
        <input class="rd-f" data-k="keterangan" value="${esc(r.keterangan || '')}" placeholder="Keterangan (opsional)">
      </div>
    </div>`).join('');
}

/** Membaca isian form ke C.rd sebelum baris ditambah/dihapus/disimpan. */
function rdBaca() {
  C.rd = $$('#rd-rows .rd-item').map(el => {
    const o = {};
    $$('.rd-f', el).forEach(f => { o[f.dataset.k] = f.value; });
    const lama = C.rd[Number(el.dataset.i)] || {};
    if (lama.id_rundown) o.id_rundown = lama.id_rundown;
    return o;
  });
}

function rdTambah() {
  rdBaca();
  const last = C.rd[C.rd.length - 1];
  C.rd.push({ jam_mulai: last && last.jam_selesai || '' });
  rdRender();
  const items = $$('#rd-rows .rd-item');
  $('input[data-k=kegiatan]', items[items.length - 1]).focus();
}

function rdHapus(i) { rdBaca(); C.rd.splice(i, 1); if (!C.rd.length) C.rd.push({}); rdRender(); }

function rdSimpan(btn, status) {
  rdBaca();
  busy(btn, async () => {
    await call('rundownSimpan', { id_hari: C.idHari, items: C.rd, status: status });
    toast(status === 'terbit' ? 'Rundown diterbitkan ke peserta.' : 'Draft rundown disimpan.', 'ok');
    tabRundown();
  });
}

function rdSalin(btn) {
  rdBaca();
  if (C.rd.some(r => r.kegiatan) && !confirm('Ganti rundown hari ini dengan salinan dari hari yang dipilih?')) return;
  busy(btn, async () => {
    await call('rundownSalin', { id_hari: C.idHari, id_hari_asal: $('#rd-asal').value });
    toast('Rundown disalin sebagai draft.', 'ok');
    tabRundown();
  });
}

// ----------------------------------------------------- Tab: Materi
async function tabMateri() {
  const v = C.view;
  try {
    await muatHari();
    if (!C.idHari) return setBody(v, '<div class="card muted center">Belum ada hari pelatihan.</div>');
    const list = await call('materiList', { id_hari: C.idHari });
    if (v !== C.view) return;
    setBody(v, `
      <div class="card"><div class="row">${hariSelect('tabMateri')}</div></div>
      <div class="card">
        <h2>Upload materi</h2>
        <form onsubmit="event.preventDefault(); uploadMateri(this)">
          <div class="grid2">
            <div><label>Kategori</label>
              <select name="kategori">${Object.keys(KATEGORI_MATERI).map(k => `<option value="${k}">${KATEGORI_MATERI[k]}</option>`).join('')}</select></div>
            <div><label>Tampil ke peserta mulai jam (opsional)</label><input type="time" name="tampil_mulai"></div>
          </div>
          <label>File (PDF, PPT, Word, Excel, gambar — maks. 20 MB)</label>
          <input type="file" name="file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
          <label>…atau link (Google Drive, YouTube, dll.)</label>
          <input name="link" placeholder="https://…">
          <label>Judul</label>
          <input name="judul" placeholder="Kosongkan untuk memakai nama file">
          <p class="small muted">Tips: PPT sebaiknya juga diupload versi PDF agar mudah dibuka di HP. Jika jam tampil kosong, materi tampil sejak awal hari.</p>
          <button class="btn primary">Upload</button>
        </form>
      </div>
      <div class="card">
        <h2>Materi hari ini (${list.length})</h2>
        ${list.length ? list.map(m => `
          <div class="materi-item">
            <div class="materi-ikon">${IKON_FILE[m.tipe_file] || '📄'}</div>
            <div class="grow"><b>${esc(m.judul)}</b>
              <div class="small muted">${esc(KATEGORI_MATERI[m.kategori] || 'Materi')} · ${esc(m.tipe_file.toUpperCase())}
                ${m.tampil_mulai ? ' · tampil mulai ' + esc(m.tampil_mulai) : ''}</div></div>
            <div class="row">
              <a class="btn sm" href="${esc(m.buka)}" target="_blank" rel="noopener">Buka</a>
              <button class="btn sm danger" onclick="hapusMateri(this, '${esc(m.id_materi)}', '${esc(m.judul).replace(/'/g, '')}')">Hapus</button>
            </div>
          </div>`).join('') : '<div class="muted small">Belum ada materi untuk hari ini.</div>'}
      </div>`);
  } catch (e) { errBox(v, e); }
}

function bacaFile(file) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader();
    fr.onload = () => resolve(fr.result);
    fr.onerror = () => reject(new Error('File tidak terbaca.'));
    fr.readAsDataURL(file);
  });
}

function uploadMateri(form) {
  const file = form.file.files[0];
  const link = form.link.value.trim();
  if (!file && !link) return toast('Pilih file atau isi link.', 'error');
  if (file && file.size > 20 * 1024 * 1024) return toast('File lebih dari 20 MB. Simpan sebagai PDF, atau upload ke Google Drive lalu gunakan link.', 'error');
  busy(form.querySelector('button.primary'), async () => {
    const data = { id_hari: C.idHari, kategori: form.kategori.value, tampil_mulai: form.tampil_mulai.value, judul: form.judul.value };
    if (file) data.file = { nama: file.name, data: await bacaFile(file) };
    else data.link = link;
    await call('materiTambah', data);
    toast('Materi diupload.', 'ok');
    tabMateri();
  }, 'Mengupload…');
}

function hapusMateri(btn, id, judul) {
  if (!confirm('Hapus materi "' + judul + '"?')) return;
  busy(btn, async () => { await call('materiHapus', { id_materi: id }); toast('Materi dihapus.', 'ok'); tabMateri(); });
}

// ----------------------------------------------------- Tab: Dokumentasi
async function tabDok() {
  const v = C.view;
  try {
    await muatHari();
    if (!C.idHari) return setBody(v, '<div class="card muted center">Belum ada hari pelatihan.</div>');
    const [list, rd] = await Promise.all([call('dokList', { id_hari: C.idHari }), call('rundownGet', { id_hari: C.idHari })]);
    if (v !== C.view) return;
    C.dok = list;
    C.dokRundown = rd.items;
    const nLaporan = list.filter(d => d.masuk_laporan).length;
    setBody(v, `
      <div class="card"><div class="row">${hariSelect('tabDok')}</div></div>
      <div class="card">
        <h2>Upload foto kegiatan</h2>
        <label>Foto (bisa pilih banyak sekaligus)</label>
        <input type="file" id="dok-files" accept="image/*" multiple>
        <label>Kegiatan (opsional)</label>
        ${kegiatanSelect('dok-rundown', '')}
        <label>Keterangan (opsional, berlaku untuk semua foto yang dipilih)</label>
        <input id="dok-ket" placeholder="mis. Praktik penggunaan APAR">
        <div id="dok-progress" class="mt hidden"><div class="progress"><span></span></div><div class="small muted mt" id="dok-progress-text"></div></div>
        <button class="btn primary mt" onclick="uploadDok(this)">Upload</button>
      </div>
      <div class="card">
        <h2>Foto hari ini (${list.length})</h2>
        ${list.length ? `<p class="small muted">${nLaporan} foto ditandai masuk laporan (✓). Klik foto untuk mengubah keterangan, kegiatan, atau menghapus.</p>
        <div class="gallery">${list.map((d, i) => `
          <button class="gallery-item" onclick="dialogDok(${i})">
            <img src="${esc(d.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="fotoGagal(this)">
            ${d.masuk_laporan ? '<span class="tag badge b-hadir">✓</span>' : ''}
          </button>`).join('')}</div>` : '<div class="muted small">Belum ada foto.</div>'}
      </div>`);
  } catch (e) { errBox(v, e); }
}

function kegiatanSelect(id, selected) {
  return `<select id="${id}"><option value="">— Tidak dikaitkan —</option>
    ${C.dokRundown.map(r => `<option value="${esc(r.id_rundown)}" ${r.id_rundown === selected ? 'selected' : ''}>${esc(r.jam_mulai)} · ${esc(r.kegiatan)}</option>`).join('')}</select>`;
}

async function uploadDok(btn) {
  const files = Array.from($('#dok-files').files || []);
  if (!files.length) return toast('Pilih foto terlebih dahulu.', 'error');
  const idRundown = $('#dok-rundown').value, ket = $('#dok-ket').value;
  const bar = $('#dok-progress span'), txt = $('#dok-progress-text');
  $('#dok-progress').classList.remove('hidden');
  let ok = 0;
  const gagal = [];
  await busy(btn, async () => {
    for (let i = 0; i < files.length; i++) {
      txt.textContent = 'Mengupload ' + (i + 1) + ' dari ' + files.length + '…';
      try {
        // Diperkecil agar hemat penyimpanan: versi tampilan 1280 px, versi laporan PDF 640 px.
        const foto = await kompresGambar(files[i], 1280, 0.7);
        const fotoKecil = await kompresGambar(files[i], 640, 0.62);
        await call('dokTambah', { id_hari: C.idHari, id_rundown: idRundown, keterangan: ket, foto: foto, foto_kecil: fotoKecil });
        ok++;
      } catch (e) { gagal.push(files[i].name + ': ' + e.message); }
      bar.style.width = Math.round((i + 1) / files.length * 100) + '%';
    }
  }, 'Mengupload…');
  if (gagal.length) toast(ok + ' foto berhasil, ' + gagal.length + ' gagal. ' + gagal[0], 'error');
  else toast(ok + ' foto diupload.', 'ok');
  tabDok();
}

function dialogDok(i) {
  const d = C.dok[i];
  openModal(`
    <img class="thumb" src="${esc(d.thumb)}" alt="" referrerpolicy="no-referrer">
    <label>Kegiatan</label>${kegiatanSelect('dd-rundown', d.id_rundown)}
    <label>Keterangan</label><input id="dd-ket" value="${esc(d.keterangan)}">
    <label class="check mt"><input type="checkbox" id="dd-lap" ${d.masuk_laporan ? 'checked' : ''}> Masukkan ke laporan PDF</label>
    <div class="row mt">
      <button class="btn danger" onclick="hapusDok(this, '${esc(d.id_dok)}')">Hapus</button>
      <a class="btn" href="${esc(d.buka)}" target="_blank" rel="noopener">Buka</a>
      <button class="btn primary grow" onclick="simpanDok(this, '${esc(d.id_dok)}')">Simpan</button>
    </div>`);
}

function simpanDok(btn, id) {
  busy(btn, async () => {
    await call('dokUbah', { id_dok: id, keterangan: $('#dd-ket').value, id_rundown: $('#dd-rundown').value, masuk_laporan: $('#dd-lap').checked });
    closeModal(); toast('Foto disimpan.', 'ok'); tabDok();
  });
}

function hapusDok(btn, id) {
  if (!confirm('Hapus foto ini?')) return;
  busy(btn, async () => { await call('dokHapus', { id_dok: id }); closeModal(); toast('Foto dihapus.', 'ok'); tabDok(); });
}

// ----------------------------------------------------- Tab: Laporan PDF
async function tabLaporan() {
  const v = C.view;
  try {
    await muatHari();
    const [list, ttd, inf] = await Promise.all([
      call('laporanList', { id_batch: C.batch.id_batch }), call('ttdSaya'), call('infoLaporanGet', { id_batch: C.batch.id_batch })]);
    if (v !== C.view) return;
    C.laporan = list;
    C.info = inf;
    setBody(v, `
      <div class="card">
        <h2>Buat laporan PDF</h2>
        <div class="grid2">
          <div><label>Jenis laporan</label>
            <select id="lap-jenis" onchange="lapJenis(this.value)">
              <option value="resmi">Laporan resmi lengkap</option>
              <option value="absensi">Laporan absensi peserta saja</option>
              <option value="harian">Laporan harian (internal)</option>
            </select></div>
          <div id="lap-hari-wrap" class="hidden"><label>Hari</label><div class="row">${hariSelect('')}</div></div>
          <div id="lap-rentang" class="hidden"><label>Nomor absen (kosongkan = semua peserta)</label>
            <div class="row"><input id="lap-dari" type="number" min="1" placeholder="dari" class="grow"> – <input id="lap-sampai" type="number" min="1" placeholder="sampai" class="grow"></div></div>
        </div>
        <p class="small muted" id="lap-ket"></p>
        <div id="lap-status" class="alert info hidden"></div>
        <button class="btn accent block big" onclick="buatLaporan(this)">📄 Buat & unduh PDF</button>
      </div>
      ${formInfoLaporan(inf)}
      ${inf.bisa_ubah_org ? formOrg(inf) : ''}
      <div class="card">
        <h2>Tanda tangan saya (laporan harian)</h2>
        ${ttd.ttd ? `<img class="thumb" src="${ttd.ttd}" alt="Tanda tangan" style="max-height:110px">`
          : '<div class="alert warn small">Belum ada tanda tangan. Halaman pengesahan laporan harian akan dikosongkan untuk tanda tangan basah.</div>'}
        <button class="btn mt" onclick="dialogTtdSaya()">${ttd.ttd ? 'Ganti tanda tangan' : '✍️ Buat tanda tangan'}</button>
      </div>
      <div class="card">
        <h2>Riwayat laporan</h2>
        ${list.length ? list.map((l, i) => `
          <div class="materi-item">
            <div class="materi-ikon">📕</div>
            <div class="grow"><b>${esc(l.nama)}</b><div class="small muted">${esc(l.dibuat_pada)} · ${esc(l.dibuat_oleh)}</div></div>
            <div class="row">
              <button class="btn sm" onclick="unduhLaporan(this, ${i})">⬇ Unduh</button>
              ${l.drive ? `<a class="btn sm" href="${esc(l.drive)}" target="_blank" rel="noopener">Drive</a>` : ''}
            </div>
          </div>`).join('') : '<div class="muted small">Belum ada laporan.</div>'}
        <p class="small muted">Semua laporan juga tersimpan di server LMS dan bisa diunduh ulang kapan saja.</p>
      </div>`);
    lapJenis('resmi');
  } catch (e) { errBox(v, e); }
}

const KET_LAPORAN = {
  resmi: 'Sesuai format Artha Safety: cover, laporan penyelenggaraan kegiatan (A–E), jadwal kegiatan, daftar materi & pemateri, laporan absensi per peserta (foto, tanda tangan, lokasi), dan dokumentasi kegiatan (foto bertanda ✓). Untuk banyak peserta bisa memakan waktu 1–5 menit.',
  absensi: 'Laporan absensi per peserta saja. Jika laporan resmi terlalu lama atau gagal karena batas waktu, buat absensi per rentang nomor absen (mis. 1–15 dan 16–30).',
  harian: 'Laporan internal satu hari: ringkasan kehadiran, rundown, daftar hadir, lampiran foto absen, dokumentasi, materi, dan pengesahan PIC.',
};

function lapJenis(v) {
  $('#lap-hari-wrap').classList.toggle('hidden', v !== 'harian');
  $('#lap-rentang').classList.toggle('hidden', v !== 'absensi');
  $('#lap-ket').textContent = KET_LAPORAN[v];
}

/** Data laporan resmi per batch (nomor permohonan, bidang, tempat, kepesertaan, persyaratan). */
function formInfoLaporan(inf) {
  const i = inf.info;
  const f = (k, label, ph) => `<div><label>${label}</label><input id="inf-${k}" value="${esc(i[k])}" placeholder="${esc(ph || '')}"></div>`;
  const cb = (k, label) => `<label class="check"><input type="checkbox" id="inf-${k}" ${i[k] ? 'checked' : ''}> ${label}</label>`;
  return `
    <div class="card">
      <h2>Data laporan resmi batch ini</h2>
      <p class="small muted">Dipakai di cover, laporan penyelenggaraan, dan kepala laporan absensi.</p>
      <div class="grid2">
        ${f('nomor_permohonan', 'Nomor Permohonan Temank3', 'mis. 231103232417')}
        ${f('judul_cover', 'Judul cover', 'AHLI K3 UMUM')}
        ${f('sub_cover', 'Sub judul cover')}
        ${f('bidang_pembinaan', 'Bidang Pembinaan', 'Calon Ahli K3 Umum')}
        ${f('bidang_jasa', 'Bidang Jasa (laporan absensi)', 'Keahlian K3 Umum')}
        ${f('jenis_personil', 'Jenis Personil', 'Ahli K3')}
        ${f('klasifikasi', 'Klasifikasi', 'Ahli K3 Umum')}
        ${f('kelas', 'Kelas', 'Non Kelas')}
        ${f('tempat_kelas', 'Tempat pelaksanaan: Kelas')}
        ${f('tempat_observasi', 'Tempat pelaksanaan: Observasi/PKL', 'mis. PT Adi Satria Abadi')}
        ${f('metode', 'Metode', 'Offline Training')}
        ${f('jumlah_pendaftar', 'Jumlah pendaftar (kosong = jumlah peserta: ' + inf.jumlah_peserta + ')')}
        ${f('jumlah_lulus', 'Jumlah peserta lulus')}
      </div>
      <label>Persyaratan administrasi yang ada</label>
      ${cb('surat_permohonan', 'Surat Permohonan')}
      ${cb('bap', 'BAP')}
      ${cb('daftar_hadir_pemateri', 'Daftar Hadir Pemateri')}
      <p class="small muted">Jadwal Kegiatan, Daftar Hadir Peserta, dan Foto Kegiatan dicentang otomatis jika datanya ada di LMS.</p>
      <button class="btn primary mt" onclick="simpanInfoLaporan(this)">Simpan data laporan</button>
    </div>`;
}

function simpanInfoLaporan(btn) {
  const info = {};
  Object.keys(C.info.info).forEach(k => {
    const el = $('#inf-' + k);
    if (el) info[k] = el.type === 'checkbox' ? el.checked : el.value;
  });
  busy(btn, async () => {
    C.info.info = await call('infoLaporanSimpan', { id_batch: C.batch.id_batch, info: info });
    toast('Data laporan disimpan.', 'ok');
  });
}

/** Data perusahaan & penandatangan (khusus Admin, berlaku untuk semua batch). */
function formOrg(inf) {
  const o = inf.org;
  const f = (k, label) => `<div><label>${label}</label><input id="org-${k}" value="${esc(o[k])}"></div>`;
  return `
    <div class="card">
      <h2>Data perusahaan & penandatangan (Admin)</h2>
      <p class="small muted">Berlaku untuk semua batch: kop, cover, dan halaman penyelenggaraan.</p>
      <div class="grid2">
        ${f('nama', 'Nama penyelenggara')}
        ${f('nomor_skp', 'Nomor SKP')}
        ${f('masa_skp', 'Masa berlaku SKP')}
        ${f('penandatangan', 'Nama penandatangan')}
        ${f('jabatan', 'Jabatan penandatangan (SKL)')}
        ${f('kota', 'Kota penerbitan (SKL)')}
        ${f('format_skl', 'Format nomor SKL')}
        ${f('telepon', 'Telepon')}
        ${f('telepon_kantor', 'Telepon kantor (cover)')}
        ${f('website', 'Website')}
        ${f('email', 'Email')}
      </div>
      <p class="small muted">Format nomor SKL: <code>{no}</code> nomor urut dalam batch (01, 02, …), <code>{kode}</code> kode batch pada nomor SKL
        (diisi di tab SKL), <code>{bulan}</code> bulan Romawi, <code>{bulan_angka}</code>, <code>{tahun}</code>. Nomor yang sudah terbit tidak berubah.</p>
      <label>Alamat kantor</label><input id="org-alamat" value="${esc(o.alamat)}">
      <button class="btn primary mt" onclick="simpanOrg(this)">Simpan data perusahaan</button>
      <h3 class="mt">Tanda tangan + cap penandatangan</h3>
      ${inf.org_ttd ? `<img class="thumb" src="${inf.org_ttd}" alt="Tanda tangan dan cap" style="max-height:120px">`
        : '<div class="alert warn small">Belum ada. Halaman penyelenggaraan akan dikosongkan untuk tanda tangan basah.</div>'}
      <label>Upload gambar tanda tangan + cap (PNG transparan, atau JPG berlatar putih)</label>
      <input type="file" id="org-ttd" accept="image/png,image/jpeg">
      <button class="btn mt" onclick="uploadTtdOrg(this)">Upload tanda tangan + cap</button>
    </div>`;
}

function simpanOrg(btn) {
  const org = {};
  Object.keys(C.info.org).forEach(k => { const el = $('#org-' + k); if (el) org[k] = el.value; });
  busy(btn, async () => { C.info.org = await call('orgSimpan', { org: org }); toast('Data perusahaan disimpan.', 'ok'); });
}

/** Gambar diperkecil (sisi terpanjang maks `max` px) dengan tetap menjaga transparansi PNG. */
function kompresPng(file, max) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * scale);
      c.height = Math.round(img.naturalHeight * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(img.src);
      resolve(c.toDataURL('image/png'));
    };
    img.onerror = () => reject(new Error('Bukan file gambar yang valid.'));
    img.src = URL.createObjectURL(file);
  });
}

function uploadTtdOrg(btn) {
  const file = $('#org-ttd').files[0];
  if (!file) return toast('Pilih gambar tanda tangan + cap.', 'error');
  busy(btn, async () => {
    await call('orgTtdSimpan', { gambar: await kompresPng(file, 600) });
    toast('Tanda tangan + cap disimpan.', 'ok');
    tabLaporan();
  }, 'Mengupload…');
}

/** Mengunduh file dari isi base64 (PDF dari server). */
function simpanFile(nama, base64, mime) {
  const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
  const url = URL.createObjectURL(new Blob([bytes], { type: mime }));
  const a = document.createElement('a');
  a.href = url; a.download = nama; a.target = '_blank';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

function buatLaporan(btn) {
  const jenis = $('#lap-jenis').value;
  const status = $('#lap-status');
  status.classList.remove('hidden');
  status.className = 'alert info';
  status.textContent = jenis === 'harian'
    ? 'Menyusun laporan… biasanya kurang dari 1 menit.'
    : 'Menyusun laporan… ini bisa memakan waktu 1–5 menit, jangan tutup halaman.';
  busy(btn, async () => {
    try {
      const res = await call('laporanBuat', {
        id_batch: C.batch.id_batch, jenis: jenis, id_hari: C.idHari,
        dari: $('#lap-dari').value, sampai: $('#lap-sampai').value,
      });
      simpanFile(res.nama, res.data, 'application/pdf');
      toast('Laporan dibuat: ' + res.nama, 'ok');
      tabLaporan();
    } catch (e) {
      status.className = 'alert bad';
      status.textContent = e.message + (jenis !== 'harian'
        ? ' — Jika waktu habis, buat "Laporan absensi" per rentang nomor absen.' : '');
    }
  }, 'Menyusun laporan…');
}

function unduhLaporan(btn, i) {
  busy(btn, async () => {
    const res = await call('laporanUnduh', { id_laporan: C.laporan[i].id_laporan });
    simpanFile(res.nama, res.data, 'application/pdf');
  }, '…');
}

function dialogTtdSaya() {
  openModal(`
    <h2>Tanda tangan digital</h2>
    <p class="muted small">Dipakai di halaman pengesahan laporan PDF.</p>
    <canvas id="sigpad" class="sigpad"></canvas>
    <div class="row mt">
      <button class="btn" onclick="C.pad.clear()">Hapus</button>
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn primary grow" onclick="simpanTtdSaya(this)">Simpan</button>
    </div>`);
  C.pad = signaturePad($('#sigpad'));
}

function simpanTtdSaya(btn) {
  if (C.pad.isEmpty()) return toast('Tanda tangan masih kosong.', 'error');
  busy(btn, async () => {
    await call('ttdSayaSimpan', { ttd: C.pad.toDataURL() });
    closeModal(); toast('Tanda tangan disimpan.', 'ok'); tabLaporan();
  });
}

// ----------------------------------------------------- Tab: SKL (Surat Keterangan Lulus)
const SKL_STATUS = {
  terbit: '<span class="badge b-hadir">Terbit</span>',
  ditahan: '<span class="badge b-alpa">Ditahan</span>',
  '': '<span class="badge b-none">Belum terbit</span>',
};

async function tabSkl() {
  const v = C.view;
  try {
    C.skl = await call('sklList', { id_batch: C.batch.id_batch });
    const d = C.skl, b = C.batch;
    const nTerbit = d.peserta.filter(p => p.skl.terbit).length;
    setBody(v, `
      <div class="card">
        <h2>Surat Keterangan Lulus (SKL)</h2>
        <p class="small muted">Peserta hanya bisa mengunduh SKL setelah diterbitkan di sini. Peserta yang SKL-nya belum diterbitkan
          atau <b>ditahan</b> (mis. administrasi belum lunas) tidak bisa mengunduh.</p>
        <div class="grid2">
          <div><label>Judul pelatihan</label><input value="${esc(b.nama_pelatihan)}" disabled></div>
          <div><label>Periode pelatihan</label><input value="${esc(d.periode)}" disabled></div>
          <div><label>Jenis pelatihan</label>
            <select id="skl-jenis">${['Offline', 'Online', 'Blended'].map(j => `<option ${j === d.jenis_pelatihan ? 'selected' : ''}>${j}</option>`).join('')}</select></div>
          <div><label>Kode batch pada nomor SKL</label><input id="skl-kode" value="${esc(d.kode_skl)}" placeholder="mis. A06B087KEM"></div>
          <div><label>Lembaga penerbit sertifikat</label>
            <select id="skl-lembaga">${d.lembaga_pilihan.map(l => `<option ${l === d.lembaga_skl ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
        </div>
        <p class="small muted">Contoh nomor SKL berikutnya: <b>${esc(d.contoh_nomor)}</b></p>
        <p class="small muted">Nama, judul, dan periode mengikuti data peserta & batch. Nomor SKL dibuat otomatis (unik per peserta);
          tanggal terbit = tanggal SKL diterbitkan.</p>
      </div>
      <div class="card">
        <div class="row mb"><h2 class="grow">Peserta (${nTerbit}/${d.peserta.length} terbit)</h2>
          ${nTerbit ? '<button class="btn sm" onclick="sklUnduhSemua(this)">⬇ Unduh semua SKL terbit</button>' : ''}</div>
        <div class="row mb">
          <button class="btn sm" onclick="sklPilih('semua')">Pilih semua</button>
          <button class="btn sm" onclick="sklPilih('belum')">Pilih yang belum terbit</button>
          <button class="btn sm" onclick="sklPilih('kosong')">Kosongkan pilihan</button>
        </div>
        ${d.peserta.length ? `
        <div class="table-wrap"><table>
          <thead><tr><th><input type="checkbox" id="skl-all" onchange="sklPilih(this.checked ? 'semua' : 'kosong')"></th>
            <th>No</th><th>Nama</th><th>Tempat lahir</th><th>Tanggal lahir</th><th>SKL</th></tr></thead>
          <tbody>${d.peserta.map((p, i) => `
            <tr>
              <td><input type="checkbox" class="skl-cb" value="${esc(p.id_user)}" data-terbit="${p.skl.terbit ? 1 : 0}"></td>
              <td>${esc(p.no_absen)}</td>
              <td>${esc(p.nama)}<div class="small muted">${esc(p.kode_login)}</div></td>
              <td><input class="skl-tempat" data-id="${esc(p.id_user)}" value="${esc(p.tempat_lahir)}" placeholder="mis. Sleman"></td>
              <td><input class="skl-tgl" data-id="${esc(p.id_user)}" type="date" value="${esc(p.tgl_lahir)}"></td>
              <td>${SKL_STATUS[p.skl.status] || SKL_STATUS['']}
                ${p.skl.nomor ? `<div class="small muted">${esc(p.skl.nomor)}${p.skl.terbit ? ' · ' + esc(fmtTanggal(p.skl.tgl_terbit)) : ''}</div>` : ''}
                ${p.skl.terbit ? `<button class="btn sm mt" onclick="sklUnduhPeserta(this, ${i})">⬇ PDF</button>` : ''}</td>
            </tr>`).join('')}
          </tbody></table></div>` : '<div class="muted small">Belum ada peserta di batch ini.</div>'}
        <button class="btn mt" onclick="sklSimpan(this)">Simpan data</button>
        <div class="row mt">
          <button class="btn primary grow" onclick="sklTerbit(this)">🎓 Terbitkan SKL yang dipilih</button>
          <button class="btn danger grow" onclick="sklTahan(this)">⏸ Tahan SKL yang dipilih</button>
        </div>
        <p class="small muted">Tempat & tanggal lahir wajib diisi sebelum SKL diterbitkan. SKL yang ditahan bisa diterbitkan lagi
          dengan nomor yang sama.</p>
      </div>`);
  } catch (e) { errBox(v, e); }
}

function sklPilih(mode) {
  $$('.skl-cb').forEach(cb => { cb.checked = mode === 'semua' || (mode === 'belum' && cb.dataset.terbit === '0'); });
  const all = $('#skl-all');
  if (all) all.checked = mode === 'semua';
}

function sklTerpilih() { return $$('.skl-cb:checked').map(cb => cb.value); }

function sklDataForm() {
  return {
    id_batch: C.batch.id_batch, jenis_pelatihan: $('#skl-jenis').value, kode_skl: $('#skl-kode').value, lembaga_skl: $('#skl-lembaga').value,
    peserta: $$('.skl-tempat').map(el => ({ id_user: el.dataset.id, tempat_lahir: el.value,
      tgl_lahir: $$('.skl-tgl').find(t => t.dataset.id === el.dataset.id).value })),
  };
}

function sklSimpan(btn) {
  busy(btn, async () => { await call('sklSimpanData', sklDataForm()); toast('Data SKL disimpan.', 'ok'); tabSkl(); });
}

function sklTerbit(btn) {
  const ids = sklTerpilih();
  if (!ids.length) return toast('Centang peserta yang akan diterbitkan SKL-nya.', 'error');
  if (!confirm('Terbitkan SKL untuk ' + ids.length + ' peserta? Peserta akan bisa mengunduh SKL dari akunnya.')) return;
  busy(btn, async () => {
    await call('sklSimpanData', sklDataForm());
    const res = await call('sklTerbitkan', { id_batch: C.batch.id_batch, id_users: ids });
    toast(res.terbit + ' SKL terbit.' + (res.dilewati.length ? ' Dilewati (tempat/tanggal lahir kosong): ' + res.dilewati.join(', ') : ''),
      res.dilewati.length ? 'error' : 'ok');
    tabSkl();
  }, 'Menerbitkan…');
}

function sklTahan(btn) {
  const ids = sklTerpilih();
  if (!ids.length) return toast('Centang peserta yang SKL-nya akan ditahan.', 'error');
  if (!confirm('Tahan SKL ' + ids.length + ' peserta? Peserta tidak bisa mengunduh SKL sampai diterbitkan lagi.')) return;
  busy(btn, async () => {
    const n = await call('sklTahan', { id_batch: C.batch.id_batch, id_users: ids });
    toast(n + ' SKL ditahan.', 'ok');
    tabSkl();
  });
}

function sklUnduhPeserta(btn, i) {
  busy(btn, async () => {
    const res = await call('sklUnduh', { id_user: C.skl.peserta[i].id_user });
    simpanFile(res.nama, res.data, 'application/pdf');
  }, '…');
}

function sklUnduhSemua(btn) {
  busy(btn, async () => {
    const res = await call('sklUnduhSemua', { id_batch: C.batch.id_batch });
    simpanFile(res.nama, res.data, 'application/pdf');
  }, 'Menyusun PDF…');
}

// ----------------------------------------------------- Tab: Dashboard (PIC & Admin)
const LEVEL_PENGINGAT = { bad: ['b-alpa', 'Segera'], warn: ['b-belum', 'Perlu'], info: ['b-izin', 'Info'] };
const FASE_BATCH = { berjalan: ['b-hadir', 'Berjalan'], akan: ['b-izin', 'Akan datang'], selesai: ['b-none', 'Selesai'] };
const TAB_LABEL = { peserta: 'Peserta', lokasi: 'Jadwal & Lokasi', rundown: 'Rundown', materi: 'Materi', dok: 'Dokumentasi',
  monitor: 'Absensi', narasumber: 'Absensi Narasumber', kepatuhan: 'Tata Tertib & Video', pustaka: 'Pustaka', laporan: 'Laporan', skl: 'SKL', batch: 'Batch' };

async function tabDashboard() {
  const v = C.view;
  try {
    const d = await call('dasborPic');
    C.dasbor = d;
    const bs = d.batches;
    const semua = [];
    bs.forEach((b, i) => b.pengingat.forEach((p, j) => semua.push({ b: b, i: i, j: j, p: p })));
    const urut = { bad: 0, warn: 1, info: 2 };
    semua.sort((x, y) => urut[x.p.level] - urut[y.p.level]);
    const berjalan = bs.filter(b => b.fase === 'berjalan');
    let hadir = 0, total = 0;
    berjalan.forEach(b => (b.hari_ini ? b.hari_ini.sesi : []).forEach(s => {
      if (s.state !== 'belum_dibuka') { hadir += s.hadir; total += b.peserta; }
    }));
    const penting = semua.filter(x => x.p.level !== 'info').length;
    setBody(v, `
      <p class="muted" style="margin-top:-4px">Halo, ${esc(S.user.nama)} 👋 · ${esc(fmtTanggal(d.tanggal))}, ${esc(d.jam)} WIB</p>
      <div class="dash-stats">
        <div class="card stat-card"><span class="chip chip-blue">Batch berjalan</span><div class="stat-main">${berjalan.length}</div>
          <div class="small muted">${bs.filter(b => b.fase === 'akan').length} akan datang · ${bs.filter(b => b.fase === 'selesai').length} selesai</div></div>
        <div class="card stat-card"><span class="chip chip-blue">Peserta aktif</span><div class="stat-main">${berjalan.reduce((n, b) => n + b.peserta, 0)}</div>
          <div class="small muted">di batch yang sedang berjalan</div></div>
        <div class="card stat-card"><span class="chip chip-green">Kehadiran hari ini</span><div class="stat-main">${total ? Math.round(hadir / total * 100) + '%' : '–'}</div>
          <div class="small muted">${total ? hadir + ' dari ' + total + ' absen sesi' : 'belum ada sesi yang dibuka'}</div></div>
        <div class="card stat-card"><span class="chip ${penting ? 'chip-live' : 'chip-green'}">Pengingat</span><div class="stat-main">${penting}</div>
          <div class="small muted">perlu ditindaklanjuti${semua.length > penting ? ' · ' + (semua.length - penting) + ' info' : ''}</div></div>
      </div>
      <div class="card">
        <h2>🔔 Pengingat</h2>
        ${semua.length ? `<div class="reminder-list">${semua.map(x => `
          <div class="reminder r-${x.p.level}">
            <span class="badge ${LEVEL_PENGINGAT[x.p.level][0]}">${LEVEL_PENGINGAT[x.p.level][1]}</span>
            <div class="grow"><b>${esc(x.b.kode_batch)}</b> · ${esc(x.p.teks)}</div>
            <button class="btn sm" onclick="bukaPengingat(${x.i}, ${x.j})">Buka ${esc(TAB_LABEL[x.p.tab] || '')} →</button>
          </div>`).join('')}</div>`
          : '<div class="alert info small">✅ Semua beres. Tidak ada pengingat saat ini.</div>'}
      </div>
      <h2 class="section-title">Batch saya</h2>
      ${bs.length ? `<div class="grid2">${bs.map((b, i) => `
        <div class="card dash-batch">
          <div class="row"><div class="grow"><b>${esc(b.kode_batch)}</b> <span class="badge ${FASE_BATCH[b.fase][0]}">${FASE_BATCH[b.fase][1]}</span>
            <div class="small">${esc(b.nama_pelatihan)}</div></div>
            <button class="btn sm" onclick="bukaBatchDasbor(${i}, 'monitor')">Buka</button></div>
          <div class="small muted mt">${esc(fmtTanggal(b.tgl_mulai))} – ${esc(fmtTanggal(b.tgl_selesai))} · ${b.peserta} peserta</div>
          ${b.hari_ini ? `
            <div class="small mt"><b>Hari ke-${esc(b.hari_ini.hari_ke)} dari ${esc(b.hari_ini.total_hari)}</b></div>
            ${b.hari_ini.sesi.map(s => {
              const pct = b.peserta ? Math.round(s.hadir / b.peserta * 100) : 0;
              const ket = s.state === 'belum_dibuka' ? 'belum dibuka' : s.state === 'buka' ? 'berlangsung' : 'ditutup';
              return `<div class="dash-sesi"><div class="row small"><span class="grow">Sesi ${s.sesi} · ${esc(s.mulai)}–${esc(s.selesai)} (${ket})</span>
                <b>${s.hadir}/${b.peserta}</b></div><div class="bar"><span style="width:${pct}%"></span></div></div>`;
            }).join('')}`
            : `<div class="small muted mt">${b.fase === 'akan' ? 'Mulai ' + esc(fmtTanggal(b.tgl_mulai)) : b.fase === 'selesai' ? 'Pelatihan sudah selesai'
              : b.berikutnya ? 'Tidak ada jadwal hari ini · berikutnya ' + esc(fmtTanggal(b.berikutnya)) : 'Tidak ada jadwal hari ini'}</div>`}
          <div class="small mt">${b.pengingat.length ? '🔔 ' + b.pengingat.length + ' pengingat' : '✅ Tidak ada pengingat'}</div>
        </div>`).join('')}</div>`
        : `<div class="card center muted">${S.user.role === 'admin' ? 'Belum ada batch aktif. Buat batch baru di menu <b>Batch</b>.'
          : 'Anda belum ditugaskan ke batch mana pun. Hubungi Admin.'}</div>`}`);
  } catch (e) { errBox(v, e); }
}

/** Pindah ke batch & tab tujuan pengingat (dan hari terkait, jika ada). */
function bukaPengingat(i, j) {
  const b = C.dasbor.batches[i], p = b.pengingat[j];
  bukaBatchDasbor(i, p.tab, p.id_hari);
}

function bukaBatchDasbor(i, tab, idHari) {
  const id = C.dasbor.batches[i].id_batch;
  if (tab !== 'batch') {
    C.batch = C.batches.find(b => b.id_batch === id) || C.batch;
    store('lms_pic_batch', id);
    if (C.hariFor !== id) C.hariFor = null;
    C.idHari = idHari || null;
  }
  picTab(tab);
}

// ----------------------------------------------------- Tab: Absensi narasumber (tanda tangan saja)
async function tabNarasumber() {
  const v = C.view;
  try {
    await muatHari();
    if (!C.idHari) return setBody(v, '<div class="card center muted">Batch ini belum memiliki hari pelatihan.</div>');
    const res = await call('narasumberList', { id_hari: C.idHari });
    C.ns = res.items;
    const h = hariAktif();
    const nanti = !res.bisa_isi;
    const sudah = C.ns.filter(x => x.absen).length;
    setBody(v, `
      <div class="card">
        <div class="row">${hariSelect('tabNarasumber')}</div>
        <p class="small muted mt">Setiap narasumber menandatangani daftar hadir di HP/laptop PIC pada sesinya (tanpa foto).
          Daftar diambil dari <b>Rundown</b> (kegiatan yang mencantumkan pemateri) dan otomatis masuk ke laporan resmi &amp; laporan harian.</p>
      </div>
      <div class="card">
        <div class="row mb"><h2 class="grow">Narasumber ${esc('Hari ke-' + h.hari_ke)} (${sudah}/${C.ns.length} tanda tangan)</h2>
          ${nanti ? '' : '<button class="btn sm" onclick="dialogNarasumber(-1)">＋ Narasumber lain</button>'}</div>
        ${nanti ? '<div class="alert info small">Absensi narasumber bisa diisi mulai hari pelatihan ini berlangsung.</div>' : ''}
        ${C.ns.length ? `<div class="table-wrap"><table>
          <thead><tr><th>Waktu</th><th>Materi</th><th>Narasumber</th><th>Tanda tangan</th><th></th></tr></thead>
          <tbody>${C.ns.map((x, i) => `
            <tr>
              <td class="small">${esc(x.jam_mulai)}–${esc(x.jam_selesai)}</td>
              <td>${esc(x.materi)}</td>
              <td>${esc(x.nama)}<div class="small muted">${esc(x.instansi)}</div></td>
              <td>${x.absen ? `<img src="${x.absen.ttd}" alt="Tanda tangan" style="height:46px;background:#fff">
                  <div class="small muted">${esc(jamSaja(x.absen.waktu))} WIB · ${esc(x.absen.oleh)}</div>`
                : '<span class="badge b-belum">Belum</span>'}</td>
              <td>${x.absen ? `<button class="btn sm danger" onclick="hapusNarasumber(this, ${i})">Ulangi</button>`
                : nanti ? '' : `<button class="btn sm primary" onclick="dialogNarasumber(${i})">✍️ Tanda tangan</button>`}</td>
            </tr>`).join('')}
          </tbody></table></div>`
          : `<div class="muted small">Belum ada narasumber untuk hari ini. Isi nama <b>Pemateri</b> pada kegiatan di menu <b>Rundown</b>,
              atau catat lewat tombol <b>＋ Narasumber lain</b>.</div>`}
      </div>`);
  } catch (e) { errBox(v, e); }
}

/** i = indeks baris dari rundown; -1 = narasumber lain (diisi manual). */
function dialogNarasumber(i) {
  const x = i >= 0 ? C.ns[i] : null;
  openModal(`
    <h2>Absensi narasumber</h2>
    ${x ? `<p class="small muted">${esc(x.jam_mulai)}–${esc(x.jam_selesai)} · ${esc(x.materi)}</p>` : `
      <label>Materi / kegiatan</label><input id="ns-materi" placeholder="mis. Dasar-dasar K3">
      <div class="grid2">
        <div><label>Jam mulai</label><input id="ns-mulai" type="time"></div>
        <div><label>Jam selesai</label><input id="ns-selesai" type="time"></div>
      </div>`}
    <label>Nama narasumber</label><input id="ns-nama" value="${esc(x ? x.nama : '')}">
    <label>Instansi</label><input id="ns-instansi" value="${esc(x ? x.instansi : '')}" placeholder="mis. Disnakertrans Prov. DIY / Praktisi K3">
    <label class="mt">Tanda tangan narasumber</label>
    <div class="seg">
      <button type="button" id="ns-m-pad" class="active" onclick="modeTtdNs(false)">✍️ Tanda tangan langsung</button>
      <button type="button" id="ns-m-file" onclick="modeTtdNs(true)">📁 Upload file</button>
    </div>
    <div id="ns-pad">
      <canvas id="sigpad" class="sigpad"></canvas>
      <p class="small muted">Serahkan HP/laptop kepada narasumber untuk tanda tangan. Waktu tercatat otomatis dari server.</p>
    </div>
    <div id="ns-file" class="hidden">
      <input type="file" id="ns-ttd-file" accept="image/png,image/jpeg,image/webp" onchange="pilihTtdNs(this)">
      <p class="small muted">Foto/scan tanda tangan di kertas putih (JPG/PNG). Latar otomatis dibuang menjadi transparan (PNG) agar rapi di laporan.</p>
      <div id="ns-ttd-preview"></div>
    </div>
    <div class="row mt">
      <button class="btn" id="ns-hapus" onclick="C.pad.clear()">Hapus</button>
      <button class="btn" onclick="closeModal()">Batal</button>
      <button class="btn primary grow" onclick="simpanNarasumber(this, ${i})">Simpan</button>
    </div>`);
  C.pad = signaturePad($('#sigpad'));
  C.nsFile = null;
  C.nsModeFile = false;
}

function modeTtdNs(file) {
  C.nsModeFile = file;
  $('#ns-m-pad').classList.toggle('active', !file);
  $('#ns-m-file').classList.toggle('active', file);
  $('#ns-pad').classList.toggle('hidden', file);
  $('#ns-file').classList.toggle('hidden', !file);
  $('#ns-hapus').classList.toggle('hidden', file);
}

async function pilihTtdNs(input) {
  const file = input.files[0];
  C.nsFile = null;
  $('#ns-ttd-preview').innerHTML = '';
  if (!file) return;
  try {
    C.nsFile = await ttdTransparan(file);
    $('#ns-ttd-preview').innerHTML = `<div class="small muted mt">Hasil (latar transparan):</div>
      <div class="ttd-preview"><img src="${C.nsFile}" alt="Tanda tangan"></div>`;
  } catch (e) { toast(e.message, 'error'); input.value = ''; }
}

/**
 * Gambar tanda tangan (foto/scan) → PNG berlatar transparan: piksel terang (kertas) dibuang dengan tepi halus,
 * goresan dipertahankan, lalu dipotong rapat dan diperkecil (maks. 600×240 px).
 */
function ttdTransparan(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(img.src);
      const sc = Math.min(1, 1400 / Math.max(img.naturalWidth, img.naturalHeight));
      const w = Math.max(1, Math.round(img.naturalWidth * sc)), h = Math.max(1, Math.round(img.naturalHeight * sc));
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      const ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);
      const data = ctx.getImageData(0, 0, w, h), d = data.data;
      // Kecerahan tiap piksel (dianggap di atas kertas putih jika sudah transparan sebagian).
      const lum = new Float32Array(w * h);
      const sampel = [];
      for (let i = 0, p = 0; i < d.length; i += 4, p++) {
        const a = d[i + 3] / 255;
        lum[p] = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) * a + 255 * (1 - a);
        if (p % 7 === 0) sampel.push(lum[p]);
      }
      sampel.sort((x, y) => x - y);
      const kertas = sampel[Math.floor(sampel.length * 0.9)] || 255; // perkiraan warna kertas
      const batas = kertas - 30, lebar = 70;
      let x0 = w, y0 = h, x1 = -1, y1 = -1;
      for (let p = 0; p < w * h; p++) {
        const alpha = Math.max(0, Math.min(1, (batas - lum[p]) / lebar));
        const i = p * 4;
        d[i + 3] = Math.round(alpha * 255);
        if (alpha > 0.15) {
          const x = p % w, y = (p - x) / w;
          if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
          // goresan sedikit dipertegas agar tidak pucat di PDF
          d[i] = Math.round(d[i] * 0.6); d[i + 1] = Math.round(d[i + 1] * 0.6); d[i + 2] = Math.round(d[i + 2] * 0.6);
        }
      }
      if (x1 < 0 || (x1 - x0) < 10 || (y1 - y0) < 5) return reject(new Error('Tanda tangan tidak terdeteksi. Gunakan foto tanda tangan bertinta gelap di kertas putih.'));
      ctx.putImageData(data, 0, 0);
      const pad = 8;
      x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad); x1 = Math.min(w - 1, x1 + pad); y1 = Math.min(h - 1, y1 + pad);
      const cw = x1 - x0 + 1, ch = y1 - y0 + 1;
      const k = Math.min(1, 600 / cw, 240 / ch);
      const out = document.createElement('canvas');
      out.width = Math.round(cw * k); out.height = Math.round(ch * k);
      out.getContext('2d').drawImage(c, x0, y0, cw, ch, 0, 0, out.width, out.height);
      resolve(out.toDataURL('image/png'));
    };
    img.onerror = () => reject(new Error('File bukan gambar yang valid (gunakan JPG atau PNG).'));
    img.src = URL.createObjectURL(file);
  });
}

function simpanNarasumber(btn, i) {
  if (C.nsModeFile ? !C.nsFile : C.pad.isEmpty()) return toast(C.nsModeFile ? 'Pilih file gambar tanda tangan.' : 'Tanda tangan masih kosong.', 'error');
  const x = i >= 0 ? C.ns[i] : null;
  const data = { id_hari: C.idHari, nama: $('#ns-nama').value, instansi: $('#ns-instansi').value, ttd: C.nsModeFile ? C.nsFile : C.pad.toDataURL() };
  if (x) data.id_rundown = x.id_rundown;
  else Object.assign(data, { materi: $('#ns-materi').value, jam_mulai: $('#ns-mulai').value, jam_selesai: $('#ns-selesai').value });
  busy(btn, async () => {
    await call('narasumberAbsen', data);
    closeModal(); toast('Absensi ' + data.nama + ' tersimpan.', 'ok'); tabNarasumber();
  });
}

function hapusNarasumber(btn, i) {
  const x = C.ns[i];
  if (!confirm('Hapus tanda tangan ' + x.nama + ' agar bisa diulang?')) return;
  busy(btn, async () => { await call('narasumberHapus', { id_an: x.absen.id_an }); toast('Tanda tangan dihapus.', 'ok'); tabNarasumber(); });
}

// ----------------------------------------------------- Form batch: program, skema, tanggal (rentang / pilihan)
function namaProgram(id) {
  const p = (C.programs || []).find(x => x.id_program === id);
  return p ? p.nama : '(program dihapus)';
}

function programSkemaHtml(b) {
  const list = (C.programs || []).filter(p => p.aktif || p.id_program === b.id_program);
  return `
    <div class="grid2">
      <div><label>Program pelatihan</label>
        <select name="id_program"><option value="">— belum dipilih —</option>
          ${list.map(p => `<option value="${esc(p.id_program)}" ${p.id_program === b.id_program ? 'selected' : ''}>${esc(p.nama)}</option>`).join('')}</select></div>
      <div><label>Skema pelatihan</label>
        <select name="jenis_pelatihan"><option value="">— belum dipilih —</option>
          ${['Offline', 'Online', 'Blended'].map(j => `<option ${j === b.jenis_pelatihan ? 'selected' : ''}>${j}</option>`).join('')}</select></div>
    </div>
    <p class="small muted">Program & skema menentukan tata tertib dan guideline yang tampil ke peserta.
      ${list.length ? '' : 'Belum ada program — tambahkan di menu <b>Pustaka Pelatihan</b>.'}</p>`;
}

/** Pilihan tanggal: rentang (mulai–selesai) atau tanggal pilihan yang tidak harus berurutan. */
function formTanggalHtml(pre, b) {
  const custom = !!b.tanggal_custom;
  return `
    <label>Tanggal pelatihan</label>
    <div class="seg seg-tgl">
      <button type="button" id="${pre}-m-rentang" class="${custom ? '' : 'active'}" onclick="modeTanggal('${pre}', false)">Rentang tanggal</button>
      <button type="button" id="${pre}-m-custom" class="${custom ? 'active' : ''}" onclick="modeTanggal('${pre}', true)">Pilih tanggal sendiri</button>
    </div>
    <div id="${pre}-rentang" class="${custom ? 'hidden' : ''}">
      <div class="grid2">
        <div><label>Tanggal mulai</label><input type="date" name="tgl_mulai" value="${esc(custom ? '' : b.tgl_mulai || '')}"></div>
        <div><label>Tanggal selesai</label><input type="date" name="tgl_selesai" value="${esc(custom ? '' : b.tgl_selesai || '')}"></div>
      </div>
      <label class="check mt"><input type="checkbox" name="lewati_weekend" ${b.lewati_weekend === 'ya' ? 'checked' : ''}> Lewati Sabtu & Minggu</label>
    </div>
    <div id="${pre}-custom" class="${custom ? '' : 'hidden'}">
      <div class="row"><input type="date" id="${pre}-tc-input" class="grow">
        <button type="button" class="btn" onclick="tcTambah('${pre}')">＋ Tambah tanggal</button></div>
      <div class="tc-list" id="${pre}-tc-list">${tcHtml(pre)}</div>
      <p class="small muted">Contoh: 1, 3, 9 dan 14 → Hari ke-1 s/d ke-4. Tanggal yang sudah berisi absensi tidak akan dihapus.</p>
    </div>
    <input type="hidden" name="mode_tgl_${pre}" value="${custom ? 'custom' : 'rentang'}">`;
}

function modeTanggal(pre, custom) {
  $('#' + pre + '-m-rentang').classList.toggle('active', !custom);
  $('#' + pre + '-m-custom').classList.toggle('active', custom);
  $('#' + pre + '-rentang').classList.toggle('hidden', custom);
  $('#' + pre + '-custom').classList.toggle('hidden', !custom);
  $('input[name=mode_tgl_' + pre + ']').value = custom ? 'custom' : 'rentang';
}

function tcHtml(pre) {
  const list = (C.tc && C.tc[pre]) || [];
  return list.length ? list.map((t, i) => `<span class="tc-chip"><b>Hari ${i + 1}</b> ${esc(fmtTanggal(t))}
      <button type="button" title="Hapus" onclick="tcHapus('${pre}', '${t}')">✕</button></span>`).join('')
    : '<span class="small muted">Belum ada tanggal dipilih.</span>';
}

function tcTambah(pre) {
  const el = $('#' + pre + '-tc-input');
  if (!el.value) return toast('Pilih tanggal dulu.', 'error');
  C.tc[pre] = Array.from(new Set((C.tc[pre] || []).concat(el.value))).sort();
  el.value = '';
  $('#' + pre + '-tc-list').innerHTML = tcHtml(pre);
}

function tcHapus(pre, t) {
  C.tc[pre] = C.tc[pre].filter(x => x !== t);
  $('#' + pre + '-tc-list').innerHTML = tcHtml(pre);
}

/** Data tanggal dari form; null (dengan pesan) jika belum lengkap. */
function tanggalForm(form, pre) {
  if (form['mode_tgl_' + pre].value === 'custom') {
    if (!C.tc[pre].length) { toast('Tambahkan minimal satu tanggal pelatihan.', 'error'); return null; }
    return { tanggal_custom: C.tc[pre], lewati_weekend: false };
  }
  if (!form.tgl_mulai.value || !form.tgl_selesai.value) { toast('Isi tanggal mulai dan tanggal selesai.', 'error'); return null; }
  return { tgl_mulai: form.tgl_mulai.value, tgl_selesai: form.tgl_selesai.value, lewati_weekend: form.lewati_weekend.checked, tanggal_custom: [] };
}

// ----------------------------------------------------- Tab: Pustaka pelatihan (Admin)
const SKEMA_LABEL = { semua: 'Semua skema', Offline: 'Offline', Online: 'Online', Blended: 'Blended' };

async function tabPustaka() {
  const v = C.view;
  try {
    const [programs, dok, video] = await Promise.all([call('programList'), call('pustakaDokList'), call('videoList')]);
    C.programs = programs; C.pdok = dok; C.video = video;
    const aktif = programs.filter(p => p.aktif);
    // Dokumen dikelompokkan per program → skema + jenis; versi terbaru di atas.
    const grup = {};
    dok.forEach(d => { const k = d.id_program + '|' + d.skema + '|' + d.jenis; (grup[k] = grup[k] || []).push(d); });
    setBody(v, `
      <div class="card">
        <h2>📚 Program pelatihan</h2>
        <p class="small muted">Kelompok untuk batch sejenis, mis. <i>Petugas P3K</i>. Setiap batch dihubungkan ke satu program di menu <b>Batch → Ubah</b>.</p>
        <form class="row" onsubmit="event.preventDefault(); simpanProgram(this)">
          <input name="nama" class="grow" placeholder="Nama program, mis. Petugas P3K" required>
          <button class="btn primary">＋ Tambah program</button>
        </form>
        ${programs.length ? `<div class="table-wrap mt"><table><thead><tr><th>Program</th><th>Batch</th><th></th></tr></thead><tbody>
          ${programs.map((p, i) => `<tr><td><b>${esc(p.nama)}</b> ${p.aktif ? '' : '<span class="badge b-none">nonaktif</span>'}</td>
            <td>${p.jumlah_batch}</td>
            <td class="row"><button class="btn sm" onclick="ubahProgram(this, ${i})">✏️ Ubah nama</button>
              <button class="btn sm" onclick="aktifProgram(this, ${i})">${p.aktif ? 'Nonaktifkan' : 'Aktifkan'}</button></td></tr>`).join('')}
        </tbody></table></div>` : '<div class="small muted mt">Belum ada program.</div>'}
      </div>

      <div class="card">
        <h2>📘 Tata tertib & guideline</h2>
        <p class="small muted">Upload <b>sekali</b> per program & skema; otomatis tampil di semua batch program itu. Upload ulang untuk jenis yang sama =
          <b>revisi</b>: dipakai batch yang mulai sejak hari ini, sedangkan batch yang sudah berjalan tetap memakai versi sebelumnya.
          Dokumen <i>Semua skema</i> dipakai jika tidak ada dokumen khusus untuk skema batch.</p>
        ${aktif.length ? `
        <form onsubmit="event.preventDefault(); uploadPustaka(this)">
          <div class="grid2">
            <div><label>Program</label><select name="id_program" required>${aktif.map(p => `<option value="${esc(p.id_program)}">${esc(p.nama)}</option>`).join('')}</select></div>
            <div><label>Skema</label><select name="skema">${Object.keys(SKEMA_LABEL).map(k => `<option value="${k}">${SKEMA_LABEL[k]}</option>`).join('')}</select></div>
            <div><label>Jenis dokumen</label><select name="jenis"><option value="tata_tertib">Tata Tertib</option><option value="guideline">Guideline Pelatihan</option></select></div>
            <div><label>Judul (opsional)</label><input name="judul" placeholder="Kosongkan untuk memakai nama file"></div>
          </div>
          <label>File (PDF disarankan, maks. 20 MB)</label>
          <input type="file" name="file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
          <label>…atau link (Google Drive dll.)</label><input name="link" placeholder="https://">
          <button class="btn primary mt">Upload</button>
        </form>` : '<div class="alert warn small">Tambahkan program pelatihan terlebih dahulu.</div>'}
        ${Object.keys(grup).length ? programs.map(p => {
          const slot = Object.keys(grup).filter(k => k.startsWith(p.id_program + '|'));
          if (!slot.length) return '';
          return `<h3 class="mt">${esc(p.nama)}</h3>` + slot.map(k => {
            const versi = grup[k], d = versi[0];
            return `<div class="materi-item">
              <div class="materi-ikon">📄</div>
              <div class="grow"><b>${esc(d.jenis_label)}</b> · ${esc(SKEMA_LABEL[d.skema] || d.skema)}
                <div class="small">${esc(d.judul)}</div>
                <div class="small muted">Versi terbaru: ${esc(d.diunggah_pada.slice(0, 16))} · ${esc(d.diunggah_oleh)}</div>
                ${versi.length > 1 ? `<details class="small"><summary>Versi sebelumnya (${versi.length - 1})</summary>
                  ${versi.slice(1).map(x => `<div class="row mt"><span class="grow">${esc(x.judul)} · ${esc(x.diunggah_pada.slice(0, 16))}</span>
                    <a class="btn sm" href="${esc(x.buka)}" target="_blank" rel="noopener">Buka</a>
                    <button class="btn sm danger" onclick="hapusPustaka(this, '${esc(x.id_dp)}')">Hapus</button></div>`).join('')}</details>` : ''}</div>
              <div class="row"><a class="btn sm" href="${esc(d.buka)}" target="_blank" rel="noopener">Buka</a>
                <button class="btn sm danger" onclick="hapusPustaka(this, '${esc(d.id_dp)}')">Hapus</button></div>
            </div>`;
          }).join('');
        }).join('') : '<div class="small muted mt">Belum ada dokumen.</div>'}
      </div>

      <div class="card">
        <h2>🎬 Video pengarahan (semua batch)</h2>
        <p class="small muted">Tampil di Dashboard semua peserta sebagai video wajib ditonton. Isi link dari YouTube (tombol <i>Bagikan</i>).</p>
        <form onsubmit="event.preventDefault(); simpanVideo(this, '')">
          <div class="grid2">
            <div><label>Judul</label><input name="judul" required placeholder="mis. Technical Meeting"></div>
            <div><label>Link YouTube</label><input name="url" required placeholder="https://youtu.be/…"></div>
            <div><label>Urutan tampil</label><input name="urutan" type="number" min="1" value="${video.length + 1}"></div>
          </div>
          <button class="btn primary mt">＋ Tambah video</button>
        </form>
        ${video.map((x, i) => `
          <div class="materi-item mt">
            <img src="https://img.youtube.com/vi/${esc(x.youtube_id)}/mqdefault.jpg" alt="" style="width:120px;border-radius:8px">
            <div class="grow"><b>${esc(x.urutan)}. ${esc(x.judul)}</b> ${x.aktif ? '' : '<span class="badge b-none">disembunyikan</span>'}
              <div class="small muted">${esc(x.url)}</div></div>
            <div class="row"><button class="btn sm" onclick="dialogVideo(${i})">✏️ Ubah</button>
              <button class="btn sm danger" onclick="hapusVideo(this, ${i})">Hapus</button></div>
          </div>`).join('')}
      </div>`);
  } catch (e) { errBox(v, e); }
}

function simpanProgram(form) {
  busy(form.querySelector('button'), async () => { await call('programSimpan', { nama: form.nama.value }); toast('Program ditambahkan.', 'ok'); tabPustaka(); });
}
function ubahProgram(btn, i) {
  const p = C.programs[i];
  const nama = prompt('Nama program:', p.nama);
  if (!nama || nama === p.nama) return;
  busy(btn, async () => { await call('programSimpan', { id_program: p.id_program, nama: nama, aktif: p.aktif }); toast('Program diubah.', 'ok'); tabPustaka(); });
}
function aktifProgram(btn, i) {
  const p = C.programs[i];
  busy(btn, async () => { await call('programSimpan', { id_program: p.id_program, nama: p.nama, aktif: !p.aktif }); tabPustaka(); });
}

function uploadPustaka(form) {
  const file = form.file.files[0];
  if (!file && !form.link.value.trim()) return toast('Pilih file atau isi link.', 'error');
  if (file && file.size > 20 * 1024 * 1024) return toast('File lebih dari 20 MB. Simpan sebagai PDF atau gunakan link.', 'error');
  const ada = C.pdok.some(d => d.id_program === form.id_program.value && d.skema === form.skema.value && d.jenis === form.jenis.value);
  if (ada && !confirm('Sudah ada dokumen untuk program, skema & jenis ini. Upload sebagai REVISI?\n\nBatch yang mulai sejak hari ini memakai versi baru; batch yang sudah berjalan tetap memakai versi lama.')) return;
  busy(form.querySelector('button.primary'), async () => {
    const data = { id_program: form.id_program.value, skema: form.skema.value, jenis: form.jenis.value, judul: form.judul.value };
    if (file) data.file = { nama: file.name, data: await bacaFile(file) };
    else data.link = form.link.value.trim();
    await call('pustakaDokUpload', data);
    toast('Dokumen di-upload.', 'ok');
    tabPustaka();
  }, 'Mengupload…');
}

function hapusPustaka(btn, id) {
  if (!confirm('Hapus versi dokumen ini? Persetujuan peserta untuk versi ini ikut terhapus.')) return;
  busy(btn, async () => { await call('pustakaDokHapus', { id_dp: id }); toast('Dokumen dihapus.', 'ok'); tabPustaka(); });
}

function simpanVideo(form, id) {
  busy(form.querySelector('button.primary'), async () => {
    await call('videoSimpan', { id_video: id, judul: form.judul.value, url: form.url.value, urutan: form.urutan.value,
      aktif: form.aktif ? form.aktif.checked : true });
    closeModal(); toast('Video disimpan.', 'ok'); tabPustaka();
  });
}

function dialogVideo(i) {
  const x = C.video[i];
  openModal(`
    <h2>Ubah video</h2>
    <form onsubmit="event.preventDefault(); simpanVideo(this, '${esc(x.id_video)}')">
      <label>Judul</label><input name="judul" value="${esc(x.judul)}" required>
      <label>Link YouTube</label><input name="url" value="${esc(x.url)}" required>
      <label>Urutan tampil</label><input name="urutan" type="number" min="1" value="${esc(x.urutan)}">
      <label class="check mt"><input type="checkbox" name="aktif" ${x.aktif ? 'checked' : ''}> Tampilkan ke peserta</label>
      <div class="row mt"><button type="button" class="btn" onclick="closeModal()">Batal</button><button class="btn primary grow">Simpan</button></div>
    </form>`);
}

function hapusVideo(btn, i) {
  const x = C.video[i];
  if (!confirm('Hapus video "' + x.judul + '"? Catatan peserta yang sudah menonton ikut terhapus.')) return;
  busy(btn, async () => { await call('videoHapus', { id_video: x.id_video }); toast('Video dihapus.', 'ok'); tabPustaka(); });
}

// ----------------------------------------------------- Tab: Tata tertib & video (rekap per batch)
async function tabKepatuhan() {
  const v = C.view;
  try {
    const k = await call('kepatuhanBatch', { id_batch: C.batch.id_batch });
    const sel = x => x ? `<span class="badge b-hadir" title="${esc(x)}">✓ ${esc(jamSaja(x)) || ''}</span>` : '<span class="badge b-belum">Belum</span>';
    const nSetuju = k.peserta.filter(p => p.setuju.every(Boolean)).length;
    const nTonton = k.peserta.filter(p => p.tonton.every(Boolean)).length;
    setBody(v, `
      <div class="card">
        <h2>Yang tampil ke peserta batch ini</h2>
        <p class="small">Program: <b>${k.program ? esc(k.program) : '<span class="badge b-belum">belum dipilih</span>'}</b> ·
          Skema: <b>${k.skema ? esc(k.skema) : '<span class="badge b-belum">belum dipilih</span>'}</b></p>
        ${!k.program ? `<div class="alert warn small">Tata tertib & guideline belum tampil ke peserta karena program batch belum dipilih.
          ${S.user.role === 'admin' ? 'Pilih di menu <b>Batch → Ubah</b>.' : 'Minta Admin memilihkan program di menu Batch.'}</div>` : ''}
        ${k.dokumen.map(d => `<div class="materi-item"><div class="materi-ikon">📄</div>
          <div class="grow"><b>${esc(d.jenis_label)}</b> · ${esc(SKEMA_LABEL[d.skema] || d.skema)}<div class="small">${esc(d.judul)}</div>
            <div class="small muted">Versi ${esc(d.diunggah_pada.slice(0, 10))}</div></div>
          <a class="btn sm" href="${esc(d.buka)}" target="_blank" rel="noopener">Buka</a></div>`).join('')
          || (k.program ? `<div class="small muted">Belum ada tata tertib/guideline untuk program & skema ini.${S.user.role === 'admin' ? ' Upload di menu <b>Pustaka Pelatihan</b>.' : ''}</div>` : '')}
        <h3 class="mt">🎬 Video pengarahan</h3>
        ${k.video.length ? k.video.map(x => `<div class="small">${esc(x.urutan)}. ${esc(x.judul)}</div>`).join('') : '<div class="small muted">Belum ada video.</div>'}
      </div>
      <div class="card">
        <h2>Rekap peserta</h2>
        <p class="small muted">${k.dokumen.length ? `${nSetuju}/${k.peserta.length} peserta sudah menyetujui semua dokumen. ` : ''}${k.video.length ? `${nTonton}/${k.peserta.length} peserta sudah menonton semua video.` : ''}</p>
        ${k.peserta.length && (k.dokumen.length || k.video.length) ? `<div class="table-wrap"><table>
          <thead><tr><th>No</th><th>Nama</th>${k.dokumen.map(d => `<th>${esc(d.jenis_label)}</th>`).join('')}${k.video.map(x => `<th>🎬 ${esc(x.judul)}</th>`).join('')}</tr></thead>
          <tbody>${k.peserta.map(p => `<tr><td>${esc(p.no_absen)}</td><td>${esc(p.nama)}<div class="small muted">${esc(p.kode_login)}</div></td>
            ${p.setuju.map(sel).join('')}${p.tonton.map(sel).join('')}</tr>`).join('')}</tbody></table></div>`
          : '<div class="small muted">Belum ada data untuk direkap.</div>'}
      </div>`);
  } catch (e) { errBox(v, e); }
}
