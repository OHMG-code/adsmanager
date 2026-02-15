document.addEventListener("DOMContentLoaded", function () {
    const emisje = document.querySelectorAll('.emisja');
    const form = document.getElementById('kalkulator-form');

    function okresPasmo(godzina) {
        if (godzina === '>23:00') return 'night';
        const h = parseInt(godzina.split(':')[0]);
        if ((h >= 6 && h < 10) || (h >= 15 && h < 19)) return 'prime';
        if ((h >= 10 && h < 15) || (h >= 19 && h < 23)) return 'standard';
        return 'night';
    }

    function liczbaDniTygodnia(start, end) {
        let counts = { mon: 0, tue: 0, wed: 0, thu: 0, fri: 0, sat: 0, sun: 0 };
        let dt = new Date(start);
        const dtEnd = new Date(end);
        while (dt <= dtEnd) {
            const d = dt.getDay();
            if (d === 1) counts.mon++;
            if (d === 2) counts.tue++;
            if (d === 3) counts.wed++;
            if (d === 4) counts.thu++;
            if (d === 5) counts.fri++;
            if (d === 6) counts.sat++;
            if (d === 0) counts.sun++;
            dt.setDate(dt.getDate() + 1);
        }
        return counts;
    }

    function przeliczKampanie() {
        const dlugosc = document.getElementById("dlugosc").value;
        const dataStart = document.getElementById("data_start").value;
        const dataKoniec = document.getElementById("data_koniec").value;
        if (!dataStart || !dataKoniec) return;

        const dniTyg = liczbaDniTygodnia(dataStart, dataKoniec);
        const pasma = { prime: 0, standard: 0, night: 0 };
        const wartosci = { prime: 0, standard: 0, night: 0 };

        emisje.forEach(input => {
            const match = input.name.match(/emisja\\[(.+?)\\]\\[(.+?)\\]/);
            if (!match) return;
            const dzien = match[1], godzina = match[2];
            const ilosc = parseInt(input.value) || 0;
            const dni = dniTyg[dzien] || 0;
            const pasmo = okresPasmo(godzina);
            const cena = window.cennikSpotow?.[dlugosc]?.[pasmo] || 0;

            pasma[pasmo] += ilosc * dni;
            wartosci[pasmo] += ilosc * dni * cena;
        });

        form.querySelector('input[name="sumy[prime]"]').value = pasma.prime;
        form.querySelector('input[name="sumy[standard]"]').value = pasma.standard;
        form.querySelector('input[name="sumy[night]"]').value = pasma.night;

        const sumaSpotow = wartosci.prime + wartosci.standard + wartosci.night;
        form.querySelector('input[name="netto_spoty"]').value = sumaSpotow.toFixed(2) + " zł";

        const sumaDodatkow = 0; // Tu możesz dodać sumę dodatków jeśli będą
        form.querySelector('input[name="netto_dodatki"]').value = sumaDodatkow.toFixed(2) + " zł";

        const rabat = parseFloat(form.querySelector('input[name="rabat"]').value) || 0;
        const nettoPoRabacie = (sumaSpotow + sumaDodatkow) * (1 - rabat / 100);
        const brutto = nettoPoRabacie * 1.23;

        form.querySelector('input[name="razem_po_rabacie"]').value = nettoPoRabacie.toFixed(2) + " zł";
        form.querySelector('input[name="razem_brutto"]').value = brutto.toFixed(2) + " zł";
    }

    document.querySelectorAll('#dlugosc, #data_start, #data_koniec, .emisja, input[name="rabat"]').forEach(el => {
        el.addEventListener('input', przeliczKampanie);
    });
});
