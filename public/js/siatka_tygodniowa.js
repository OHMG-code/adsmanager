document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("kalkulator-form");
    const siatkaDiv = document.getElementById("siatka-emisji");

    const dniTygodnia = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
    const godziny = ["06:00", "09:00", "12:00", "15:00", "18:00", "21:00", ">23:00"];

    function okresPasmo(godzina) {
        if (godzina === ">23:00") return "night";
        const h = parseInt(godzina.split(":")[0]);
        if ((h >= 6 && h < 10) || (h >= 15 && h < 19)) return "prime";
        if ((h >= 10 && h < 15) || (h >= 19 && h < 23)) return "standard";
        return "night";
    }

    function przeliczKampanie() {
        const dlugosc = document.getElementById("dlugosc").value;
        const dataStart = new Date(document.getElementById("data_start").value);
        const dataKoniec = new Date(document.getElementById("data_koniec").value);
        if (!dlugosc || isNaN(dataStart) || isNaN(dataKoniec)) return;

        const dniTyg = { mon: 0, tue: 0, wed: 0, thu: 0, fri: 0, sat: 0, sun: 0 };
        let dt = new Date(dataStart);
        while (dt <= dataKoniec) {
            const d = dt.getDay();
            if (d === 1) dniTyg.mon++;
            if (d === 2) dniTyg.tue++;
            if (d === 3) dniTyg.wed++;
            if (d === 4) dniTyg.thu++;
            if (d === 5) dniTyg.fri++;
            if (d === 6) dniTyg.sat++;
            if (d === 0) dniTyg.sun++;
            dt.setDate(dt.getDate() + 1);
        }

        const pasma = { prime: 0, standard: 0, night: 0 };
        const wartosci = { prime: 0, standard: 0, night: 0 };

        document.querySelectorAll(".emisja").forEach(input => {
            const match = input.name.match(/emisja\[(\w+)\]\[(.+?)\]/);
            if (!match) return;
            const dzien = match[1];
            const godzina = match[2];
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

        const sumaDodatkow = 0;
        form.querySelector('input[name="netto_dodatki"]').value = sumaDodatkow.toFixed(2) + " zł";

        const rabat = parseFloat(form.querySelector('input[name="rabat"]').value) || 0;
        const nettoPoRabacie = (sumaSpotow + sumaDodatkow) * (1 - rabat / 100);
        const brutto = nettoPoRabacie * 1.23;

        form.querySelector('input[name="razem_po_rabacie"]').value = nettoPoRabacie.toFixed(2) + " zł";
        form.querySelector('input[name="razem_brutto"]').value = brutto.toFixed(2) + " zł";
    }

    function generujSiatke() {
        siatkaDiv.innerHTML = ""; // wyczyść poprzednią siatkę

        const tabela = document.createElement("table");
        tabela.className = "table table-bordered text-center";

        const thead = document.createElement("thead");
        const headRow = document.createElement("tr");
        headRow.innerHTML = `<th>Dzień \\ Godz</th>`;
        godziny.forEach(g => {
            headRow.innerHTML += `<th>${g}</th>`;
        });
        thead.appendChild(headRow);
        tabela.appendChild(thead);

        const tbody = document.createElement("tbody");
        dniTygodnia.forEach(dzien => {
            const row = document.createElement("tr");
            row.innerHTML = `<th>${dzien.toUpperCase()}</th>`;
            godziny.forEach(godzina => {
                const input = document.createElement("input");
                input.type = "number";
                input.min = "0";
                input.className = "form-control emisja";
                input.name = `emisja[${dzien}][${godzina}]`;
                input.value = "0";
                input.addEventListener("input", przeliczKampanie);

                const td = document.createElement("td");
                td.appendChild(input);
                row.appendChild(td);
            });
            tbody.appendChild(row);
        });

        tabela.appendChild(tbody);
        siatkaDiv.appendChild(tabela);

        przeliczKampanie();
    }

    document.getElementById("generuj-siatke").addEventListener("click", generujSiatke);
    document.querySelectorAll("#dlugosc, #data_start, #data_koniec, input[name='rabat']").forEach(el => {
        el.addEventListener("input", przeliczKampanie);
    });
});
