document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("kalkulator-form");
    const siatkaDiv = document.getElementById("siatka-emisji");

    const dniTygodnia = [
        { kod: "mon", nazwa: "Poniedziałek" },
        { kod: "tue", nazwa: "Wtorek" },
        { kod: "wed", nazwa: "Środa" },
        { kod: "thu", nazwa: "Czwartek" },
        { kod: "fri", nazwa: "Piątek" },
        { kod: "sat", nazwa: "Sobota" },
        { kod: "sun", nazwa: "Niedziela" }
    ];

    const godziny = Array.from({ length: 18 }, (_, i) => {
        const h = i + 6;
        return `${h.toString().padStart(2, '0')}:00`;
    });

    function okresPasmo(godzina) {
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
            const match = input.name.match(/^emisja\[([^\]]+)]\[([^\]]+)]$/);
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

        // Sumowanie dodatków
        let sumaDodatkow = 0;
        document.querySelectorAll(".produkt:checked").forEach(chk => {
            const cena = parseFloat(chk.getAttribute("data-cena")) || 0;
            sumaDodatkow += cena;
        });

        // Wypełnienie
        document.querySelector('input[name="sumy[prime]"]').value = pasma.prime;
        document.querySelector('input[name="sumy[standard]"]').value = pasma.standard;
        document.querySelector('input[name="sumy[night]"]').value = pasma.night;

        const sumaSpotow = wartosci.prime + wartosci.standard + wartosci.night;
        document.querySelector('input[name="netto_spoty"]').value = sumaSpotow.toFixed(2) + " zł";
        document.querySelector('input[name="netto_dodatki"]').value = sumaDodatkow.toFixed(2) + " zł";

        const rabat = parseFloat(document.querySelector('input[name="rabat"]').value) || 0;
        const nettoPoRabacie = (sumaSpotow + sumaDodatkow) * (1 - rabat / 100);
        const brutto = nettoPoRabacie * 1.23;

        document.querySelector('input[name="razem_po_rabacie"]').value = nettoPoRabacie.toFixed(2) + " zł";
        document.querySelector('input[name="razem_brutto"]').value = brutto.toFixed(2) + " zł";
    }

    function generujSiatke() {
        siatkaDiv.innerHTML = "";

        const tabela = document.createElement("table");
        tabela.className = "table table-bordered text-center align-middle";

        const thead = document.createElement("thead");
        const headRow = document.createElement("tr");
        headRow.innerHTML = `<th>Godzina \\ Dzień</th>`;
        dniTygodnia.forEach(dzien => {
            headRow.innerHTML += `<th>${dzien.nazwa}</th>`;
        });
        thead.appendChild(headRow);
        tabela.appendChild(thead);

        const tbody = document.createElement("tbody");
        godziny.forEach(godzina => {
            const row = document.createElement("tr");
            row.innerHTML = `<th>${godzina}</th>`;
            dniTygodnia.forEach(dzien => {
                const input = document.createElement("input");
                input.type = "number";
                input.min = "0";
                input.className = "form-control emisja";
                input.name = `emisja[${dzien.kod}][${godzina}]`;
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

    function autoGeneruj() {
        const dl = document.getElementById("dlugosc").value;
        const ds = document.getElementById("data_start").value;
        const dk = document.getElementById("data_koniec").value;
        if (dl && ds && dk) generujSiatke();
    }

    document.querySelectorAll("#dlugosc, #data_start, #data_koniec").forEach(el => {
        el.addEventListener("input", autoGeneruj);
    });

    document.querySelector('input[name="rabat"]').addEventListener("input", przeliczKampanie);

    document.querySelectorAll(".produkt").forEach(el => {
        el.addEventListener("change", przeliczKampanie);
    });
});
