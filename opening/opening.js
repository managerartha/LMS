/**
 * Opening Pelatihan · PT Artha Safety Indonesia
 * Halaman presentasi upacara pembukaan yang dikendalikan PIC (seperti slide). Data batch diambil dari LMS
 * (aksi openingData) memakai login PIC/Admin yang sama. Video & musik diatur di config.json.
 *
 * Tombol: → / PageDown / Spasi = lanjut · ← / PageUp = kembali · F = layar penuh · M = musik · Esc = tutup video.
 * Mode latihan tanpa LMS: index.html?demo=online | offline | inhouse
 */
(function () {
  'use strict';

  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const LANGKAH = ['Pembukaan', 'Safety Induction', 'Indonesia Raya', 'Sambutan Direktur', 'Penutup'];
  const DEMO = {
    online: { judul: 'Pembinaan dan Sertifikasi Ahli K3 Umum', periode: '1 – 12 Desember 2026', kelas: 'online', inhouse: '' },
    offline: { judul: 'Pembinaan dan Sertifikasi Ahli K3 Umum', periode: '1 – 12 Desember 2026', kelas: 'offline', inhouse: '' },
    inhouse: { judul: 'Pelatihan Petugas P3K di Tempat Kerja', periode: '8 – 10 Desember 2026', kelas: 'offline', inhouse: 'PT Contoh Energi Nusantara' },
  };
  const CONFIG_BAWAAN = {
    lms_api: '../api/index.php', lms_url: '../',
    video: { safety_induction: 'assets/video/safety-induction.mp4', indonesia_raya: 'assets/video/indonesia-raya.mp4', sambutan_direktur: 'assets/video/sambutan-direktur.mp4' },
    musik: { file: 'assets/musik-latar.mp3', volume: 0.35, volume_pelan: 0.12, persen_saat_safety_offline: 0.2, lama_fade_detik: 1.5 },
    kontak: { instagram: '@arthasafety.id', tiktok: '@arthasafety', website: 'www.arthasafetyindonesia.co.id', whatsapp: '+62 812-6363-6376 | +62 812-6363-6358' },
  };

  let CFG = CONFIG_BAWAAN, D = null, slides = [], idx = 0, sudahMulai = false;

  // ================================================================ Pesan memuat / error
  function pesan(ikon, judul, teks, aksi) {
    $('#pesan-ikon').textContent = ikon;
    $('#pesan-judul').textContent = judul;
    $('#pesan-teks').innerHTML = teks;
    $('#pesan-aksi').innerHTML = aksi || '';
  }
  function galat(judul, teks) {
    document.body.classList.remove('memuat');
    document.body.classList.add('galat');
    pesan('⚠️', judul, teks, `<a class="tbl utama" href="${esc(CFG.lms_url || '../')}">↩ Kembali ke LMS</a>`);
  }

  // ================================================================ Muat config & data batch
  async function muatConfig() {
    try {
      const r = await fetch('config.json', { cache: 'no-store' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const c = await r.json();
      CFG = Object.assign({}, CONFIG_BAWAAN, c, {
        video: Object.assign({}, CONFIG_BAWAAN.video, c.video || {}),
        musik: Object.assign({}, CONFIG_BAWAAN.musik, c.musik || {}),
        kontak: Object.assign({}, CONFIG_BAWAAN.kontak, c.kontak || {}),
      });
    } catch (e) {
      console.warn('config.json tidak terbaca, memakai pengaturan bawaan.', e);
      CFG = CONFIG_BAWAAN; // tetap jalan dengan pengaturan bawaan
    }
  }

  async function muatData() {
    const q = new URLSearchParams(location.search);
    const demo = q.get('demo');
    if (demo) {
      if (!DEMO[demo]) throw { judul: 'Mode latihan tidak dikenal', teks: 'Gunakan <b>?demo=online</b>, <b>?demo=offline</b>, atau <b>?demo=inhouse</b>.' };
      return Object.assign({ demo: true }, DEMO[demo]);
    }
    const id = (q.get('batch') || '').trim();
    if (!id) throw { judul: 'Link opening tidak lengkap', teks: 'Halaman ini dibuka dari LMS. Masuk ke LMS sebagai PIC/Admin, lalu klik tombol <b>🎬 Buka Opening</b> pada batch pelatihan.' };
    let token = null;
    try { token = localStorage.getItem('lms_token'); } catch (e) { /* penyimpanan browser diblokir */ }
    if (!token) throw { judul: 'Silakan login ke LMS dulu', teks: 'Opening memakai login LMS. Masuk ke LMS sebagai <b>PIC/Admin</b> di browser ini, lalu klik <b>🎬 Buka Opening</b> pada batch.' };
    let res;
    try {
      const r = await fetch(CFG.lms_api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store',
        body: JSON.stringify({ action: 'openingData', data: { token: token, id_batch: id } }) });
      res = await r.json();
    } catch (e) {
      throw { judul: 'Tidak dapat terhubung ke LMS', teks: 'Periksa koneksi internet, lalu muat ulang halaman ini (tekan <b>F5</b>).' };
    }
    if (!res || !res.ok) {
      const m = (res && res.error) || 'Data batch tidak dapat dimuat.';
      if (/login|sesi|akses|izin/i.test(m)) throw { judul: 'Silakan login ke LMS dulu', teks: esc(m) + '<br>Masuk ke LMS sebagai <b>PIC/Admin</b>, lalu klik <b>🎬 Buka Opening</b> lagi.' };
      throw { judul: 'Data batch tidak ditemukan', teks: esc(m) };
    }
    return res.data;
  }

  // ================================================================ Isi tampilan
  function isiTampilan() {
    document.title = 'Opening · ' + D.judul;
    const panjang = D.judul.length > 60;
    $$('.isi-judul').forEach(e => { e.textContent = D.judul; e.classList.toggle('panjang', panjang); });
    const online = D.kelas === 'online';
    $$('.isi-kapsul').forEach(e => e.innerHTML = `<div class="kapsul"><span class="kls">${online ? '💻 Kelas Online' : '🏢 Kelas Offline'}</span>` +
      `<span class="tgl">${esc(D.periode)}</span></div>`);
    $$('.isi-inhouse').forEach(e => e.innerHTML = D.inhouse ? `<span class="inhouse">IN-HOUSE · ${esc(D.inhouse)}</span>` : '');
    $('#ket-si').textContent = online ? 'Video keselamatan kelas online' : 'Disampaikan pihak tempat pelatihan';
    // slide Safety Induction sesuai jenis kelas
    slides = $$('.slide').filter(s => !s.dataset.kelas || s.dataset.kelas === D.kelas);
    $$('.slide[data-kelas]').forEach(s => { if (s.dataset.kelas !== D.kelas) s.remove(); });
    const k = CFG.kontak;
    $('#kaki').innerHTML = [
      k.instagram && `<span><i>${IKON.instagram}</i>${esc(k.instagram)}</span>`,
      k.tiktok && `<span><i>${IKON.tiktok}</i>${esc(k.tiktok)}</span>`,
      k.website && `<span><i>${IKON.web}</i>${esc(k.website)}</span>`,
      k.whatsapp && `<span><i>${IKON.wa}</i>${esc(k.whatsapp)}</span>`,
    ].filter(Boolean).join('');
    siapkanVideo();
  }

  const IKON = {
    instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2" fill="#fff" stroke="none"/></svg>',
    tiktok: '<svg viewBox="0 0 24 24" fill="#fff"><path d="M14 3h3c.2 1.9 1.6 3.4 3.5 3.6v3c-1.3 0-2.5-.4-3.5-1v6.6A5.8 5.8 0 1 1 11.2 9.4v3.1a2.8 2.8 0 1 0 2.8 2.8z"/></svg>',
    web: '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.8 2.6 15.2 0 18M12 3c-2.6 2.8-2.6 15.2 0 18"/></svg>',
    wa: '<svg viewBox="0 0 24 24" fill="#fff"><path d="M12 2.5a9.5 9.5 0 0 0-8.2 14.3L2.5 21.5l4.8-1.3A9.5 9.5 0 1 0 12 2.5zm0 1.9a7.6 7.6 0 1 1-3.9 14.1l-.3-.2-2.8.8.8-2.7-.2-.3A7.6 7.6 0 0 1 12 4.4zm-3 3.7c-.2 0-.5 0-.7.3-.3.3-1 1-1 2.3s1 2.7 1.2 2.9c.1.2 2 3.1 4.9 4.3 2.4 1 2.9.8 3.4.7.5 0 1.7-.7 1.9-1.4.2-.7.2-1.3.2-1.4l-.5-.3-1.8-.9c-.3-.1-.5-.1-.6.1l-.8 1c-.2.2-.3.2-.6.1-.3-.1-1.2-.4-2.2-1.4a8.3 8.3 0 0 1-1.6-2c-.2-.3 0-.4.1-.6l.5-.5.3-.5v-.5l-.8-2c-.2-.5-.4-.5-.6-.5z"/></svg>',
  };

  // ================================================================ Navigasi slide
  function ke(i) {
    if (!slides.length) return;
    i = Math.max(0, Math.min(slides.length - 1, i));
    if (i > 0 && !sudahMulai) return mulai();
    idx = i;
    slides.forEach((s, k) => s.classList.toggle('aktif', k === idx));
    const s = slides[idx];
    document.body.classList.toggle('tanpa-orang', s.classList.contains('vid'));
    $('#bar').classList.toggle('sembunyi', idx === 0);
    $('#kaki').classList.toggle('sembunyi', idx !== 0);
    const L = Number(s.dataset.langkah);
    $('#langkah').innerHTML = LANGKAH.map((n, k) => `<button class="lk ${k + 1 === L ? 'aktif' : k + 1 < L ? 'selesai' : ''}" data-ke="${k + 1}" title="Ke ${esc(n)}">` +
      `<b>${k + 1 < L ? '✓' : k + 1}</b><span>${esc(n)}</span></button>`).join('');
    $('#tombol-kembali').disabled = idx === 0;
    $('#tombol-lanjut').disabled = idx === slides.length - 1;
    Musik.atur();
  }

  /** Klik MULAI (gerakan pengguna) → musik boleh diputar oleh browser, lalu pindah ke Pembukaan. */
  function mulai() {
    if (!sudahMulai) { sudahMulai = true; Musik.mulai(); }
    ke(1);
  }

  // ================================================================ Musik latar (Web Audio: loop mulus + fade halus)
  const Musik = {
    ctx: null, gain: null, buffer: null, sumber: null, mode: 0, gagal: false, siap: null,
    muat() { // unduh sejak awal agar siap saat MULAI
      this.siap = fetch(CFG.musik.file).then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
        .catch(e => { this.gagal = true; console.warn('Musik latar tidak termuat:', e); return null; });
    },
    async mulai() {
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        this.ctx = new AC();
        this.gain = this.ctx.createGain();
        this.gain.gain.value = 0;
        this.gain.connect(this.ctx.destination);
        const data = await this.siap;
        if (!data) throw new Error('file musik tidak ada');
        this.buffer = await this.ctx.decodeAudioData(data);
        this.sumber = this.ctx.createBufferSource();
        this.sumber.buffer = this.buffer;
        this.sumber.loop = true;
        this.sumber.connect(this.gain);
        this.sumber.start();
        this.atur();
      } catch (e) {
        this.gagal = true;
        console.warn('Musik latar tidak dapat diputar:', e);
      }
      this.tampil();
    },
    target() {
      if (this.mode === 2 || Video.aktif) return 0;
      let v = this.mode === 1 ? Number(CFG.musik.volume_pelan) : Number(CFG.musik.volume);
      const s = slides[idx];
      if (s && s.dataset.musikKecil) v *= Number(CFG.musik.persen_saat_safety_offline);
      return Math.max(0, Math.min(1, v || 0));
    },
    atur(lama) {
      if (!this.ctx || !this.gain) return;
      if (this.ctx.state === 'suspended') this.ctx.resume();
      const t = this.ctx.currentTime, g = this.gain.gain, d = lama != null ? lama : Number(CFG.musik.lama_fade_detik) || 1.5;
      g.cancelScheduledValues(t);
      g.setValueAtTime(g.value, t);
      g.linearRampToValueAtTime(this.target(), t + d);
    },
    ganti() { this.mode = (this.mode + 1) % 3; this.atur(0.6); this.tampil(); },
    tampil() {
      const eq = $('#eq'), txt = $('#txt-musik');
      if (this.gagal) { eq.className = 'eq diam'; txt.textContent = 'Musik ✕'; $('#tombol-musik').title = 'File musik latar tidak termuat (periksa config.json)'; return; }
      eq.className = 'eq ' + ['', 'pelan', 'mati'][this.mode];
      txt.textContent = ['Musik', 'Pelan', 'Mati'][this.mode];
    },
  };

  // ================================================================ Video (tidak otomatis; diputar layar penuh saat diklik)
  function idYoutube(url) {
    const m = String(url || '').match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/))([\w-]{11})/);
    return m ? m[1] : null;
  }

  const TOMBOL_PUTAR = '<button class="putar" title="Putar video"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 4l15 8-15 8z"/></svg></button>';

  function siapkanVideo() {
    $$('.slide .video').forEach(fr => {
      const kunci = fr.dataset.video, url = String(CFG.video[kunci] || '').trim(), nama = fr.dataset.nama;
      fr.innerHTML = '';
      if (!url) { gagalVideo(fr, 'Video ' + nama + ' belum dipasang.', 'Isi alamat video di config.json (bagian "video").', false); return; }
      const yt = idYoutube(url);
      if (yt) {
        fr.dataset.youtube = yt;
        fr.innerHTML = `<img class="sampul" src="https://i.ytimg.com/vi/${yt}/hqdefault.jpg" alt="">${TOMBOL_PUTAR}<div class="label">${esc(nama)} · YouTube</div>`;
      } else {
        const v = document.createElement('video');
        v.preload = 'auto'; v.playsInline = true; v.src = url; v.muted = false;
        v.addEventListener('error', () => {
          if (!fr.contains(v) || !v.error) return;
          // bedakan file belum diupload (404) dengan masalah koneksi
          fetch(url, { method: 'HEAD', cache: 'no-store' })
            .then(r => gagalVideo(fr, r.status === 404 ? 'File video ' + nama + ' belum diupload ke hosting.' : 'Video tidak dapat dimuat, periksa koneksi internet.',
              r.status === 404 ? 'Upload ke: opening/' + url : url, r.status !== 404))
            .catch(() => gagalVideo(fr, 'Video tidak dapat dimuat, periksa koneksi internet.', url, true));
        });
        fr.appendChild(v);
        fr.insertAdjacentHTML('beforeend', `${TOMBOL_PUTAR}<div class="label">${esc(nama)}</div>`);
        fr._video = v;
      }
      $('.putar', fr).addEventListener('click', () => Video.buka(fr));
    });
  }

  function gagalVideo(fr, judul, detail, cobaLagi) {
    if (fr._video) { fr._video.removeAttribute('src'); fr._video.load(); fr._video = null; }
    fr.innerHTML = `<div class="gagal">⚠️ ${esc(judul)}${detail ? '<small>' + esc(detail) + '</small>' : ''}` +
      (cobaLagi ? '<button class="tbl utama">↻ Coba muat lagi</button>' : '') + '</div>';
    const b = $('.gagal button', fr);
    if (b) b.addEventListener('click', () => siapkanVideo());
  }

  const Video = {
    aktif: null, // bingkai video yang sedang diputar
    yt: null,
    buka(fr) {
      if (this.aktif) return;
      this.aktif = fr;
      const isi = $('#penuh-isi'), status = $('#penuh-status');
      $('#penuh').classList.add('buka');
      Musik.atur(); // musik turun perlahan sampai berhenti
      status.innerHTML = '<div class="muter"></div>Memuat video…';
      // play() dipanggil lebih dulu: masuk layar penuh "memakai" izin klik dari browser
      if (fr.dataset.youtube) { this.bukaYoutube(fr.dataset.youtube); return masukLayarPenuh(); }
      const v = fr._video;
      isi.appendChild(v); // elemen yang sama (data yang sudah di-preload tidak diunduh ulang)
      v.controls = true;
      v.currentTime = 0;
      v.onplaying = () => { status.innerHTML = ''; };
      v.onwaiting = () => { status.innerHTML = '<div class="muter"></div>Memuat video…'; };
      v.onended = () => this.tutup();
      v.onerror = () => { status.innerHTML = '⚠️ Video tidak dapat dimuat, periksa koneksi internet.<br><small>Tekan Esc untuk kembali.</small>'; };
      const p = v.play();
      if (p && p.catch) p.catch(e => { if (this.aktif) status.innerHTML = '⚠️ Video tidak dapat diputar: ' + esc(e.message) + '<br><small>Tekan Esc untuk kembali.</small>'; });
      masukLayarPenuh();
    },
    bukaYoutube(id) {
      const isi = $('#penuh-isi'), status = $('#penuh-status');
      isi.innerHTML = '<div id="yt-pemutar"></div>';
      const buat = () => {
        this.yt = new YT.Player('yt-pemutar', { videoId: id, playerVars: { autoplay: 1, rel: 0, modestbranding: 1, playsinline: 1 },
          events: {
            onReady: e => e.target.playVideo(),
            onStateChange: e => { if (e.data === 1) status.innerHTML = ''; if (e.data === 0) this.tutup(); },
            onError: () => { status.innerHTML = '⚠️ Video YouTube tidak dapat diputar. Periksa link di config.json atau koneksi internet.<br><small>Tekan Esc untuk kembali.</small>'; },
          } });
      };
      if (window.YT && YT.Player) return buat();
      window.onYouTubeIframeAPIReady = buat;
      const s = document.createElement('script');
      s.src = 'https://www.youtube.com/iframe_api';
      s.onerror = () => { status.innerHTML = '⚠️ YouTube tidak dapat dimuat, periksa koneksi internet.<br><small>Tekan Esc untuk kembali.</small>'; };
      document.head.appendChild(s);
    },
    tutup() {
      const fr = this.aktif;
      if (!fr) return;
      this.aktif = null;
      $('#penuh').classList.remove('buka');
      $('#penuh-status').innerHTML = '';
      if (this.yt) { try { this.yt.destroy(); } catch (e) { /* abaikan */ } this.yt = null; $('#penuh-isi').innerHTML = ''; }
      const v = fr._video;
      if (v) {
        v.pause(); v.controls = false; v.onplaying = v.onwaiting = v.onended = v.onerror = null;
        try { v.currentTime = 0; } catch (e) { /* abaikan */ }
        fr.insertBefore(v, fr.firstChild); // kembali ke bingkai; tetap di slide yang sama
      }
      Musik.atur(); // musik naik lagi perlahan
    },
  };

  // ================================================================ Layar penuh & kontrol
  function masukLayarPenuh() {
    const el = document.documentElement;
    if (!document.fullscreenElement && el.requestFullscreen) el.requestFullscreen().catch(() => { /* tetap jalan tanpa layar penuh */ });
  }
  function toggleLayarPenuh() {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else masukLayarPenuh();
  }

  function pasangKontrol() {
    $('#tombol-mulai').addEventListener('click', mulai);
    $('#tombol-lanjut').addEventListener('click', () => ke(idx + 1));
    $('#tombol-kembali').addEventListener('click', () => ke(idx - 1));
    $('#tombol-musik').addEventListener('click', () => Musik.ganti());
    $('#tombol-tutup-video').addEventListener('click', () => Video.tutup());
    $('#langkah').addEventListener('click', e => {
      const b = e.target.closest('[data-ke]');
      if (!b) return;
      const L = Number(b.dataset.ke), i = slides.findIndex(s => Number(s.dataset.langkah) === L);
      if (i >= 0) ke(i);
    });
    document.addEventListener('keydown', e => {
      if (e.ctrlKey || e.altKey || e.metaKey || document.body.classList.contains('galat')) return;
      const k = e.key;
      if (Video.aktif) { // saat video: hanya tutup; tombol clicker tidak memindah slide
        if (k === 'Escape' || k === 'Backspace') { e.preventDefault(); Video.tutup(); }
        else if (['ArrowRight', 'ArrowLeft', 'PageDown', 'PageUp'].includes(k)) e.preventDefault();
        return;
      }
      if (['ArrowRight', 'PageDown', ' ', 'Enter'].includes(k)) {
        if (k === 'Enter' && e.target.closest && e.target.closest('button')) return; // Enter pada tombol → klik tombol itu
        e.preventDefault(); ke(idx + 1);
      } else if (['ArrowLeft', 'PageUp'].includes(k)) { e.preventDefault(); ke(idx - 1); }
      else if (k === 'f' || k === 'F') { e.preventDefault(); toggleLayarPenuh(); }
      else if ((k === 'm' || k === 'M') && sudahMulai) Musik.ganti();
      else if (k === 'Home') ke(0);
    });
    // keluar layar penuh dengan Esc saat video diputar → video ditutup juga
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && Video.aktif) Video.tutup(); });
    // kursor disembunyikan bila mouse diam 3 detik (rapi di proyektor)
    let t;
    document.addEventListener('mousemove', () => {
      document.body.classList.remove('diam');
      clearTimeout(t);
      t = setTimeout(() => document.body.classList.add('diam'), 3000);
    });
  }

  // ================================================================ Mulai
  async function boot() {
    pesan('⏳', 'Memuat opening…', 'Menyiapkan data pelatihan.');
    await muatConfig();
    try {
      D = await muatData();
    } catch (e) {
      return galat(e.judul || 'Terjadi kesalahan', e.teks || esc(e.message || e));
    }
    Musik.muat();
    isiTampilan();
    pasangKontrol();
    Musik.tampil();
    document.body.classList.remove('memuat');
    ke(0);
  }

  // untuk pemeriksaan (konsol browser): OPENING.volume() → volume musik saat ini
  window.OPENING = { volume: () => (Musik.gain ? Math.round(Musik.gain.gain.value * 100) / 100 : null), mode: () => Musik.mode };

  boot();
})();
