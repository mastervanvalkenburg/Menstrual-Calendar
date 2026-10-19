(() => {
  const root = document.querySelector("[data-calendar]");
  if (!root) return;

  const grid = root.querySelector("[data-grid]");
  const label = root.querySelector("[data-month-label]");
  const list = root.querySelector("[data-entry-list]");

  let current = new Date();
  current.setDate(1);
  let entries = [];

  const pad = (number) => String(number).padStart(2, "0");
  const monthKey = (date) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;

  async function load() {
    const body = new URLSearchParams({
      action: "kp_get_entries",
      nonce: KPKalender.nonce,
      month: monthKey(current)
    });

    try {
      const response = await fetch(KPKalender.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body
      });

      const result = await response.json();
      if (!result.success) throw new Error("Einträge konnten nicht geladen werden.");

      entries = result.data;
      render();
    } catch (error) {
      list.textContent = error.message;
    }
  }

  function render() {
    const year = current.getFullYear();
    const month = current.getMonth();

    label.textContent = new Intl.DateTimeFormat("de-DE", {
      month: "long",
      year: "numeric"
    }).format(current);

    grid.replaceChildren();

    const offset = (new Date(year, month, 1).getDay() + 6) % 7;
    const days = new Date(year, month + 1, 0).getDate();

    for (let i = 0; i < offset; i++) {
      const blank = document.createElement("div");
      blank.className = "kp-day kp-empty";
      grid.append(blank);
    }

    for (let day = 1; day <= days; day++) {
      const date = `${year}-${pad(month + 1)}-${pad(day)}`;
      const cell = document.createElement("div");
      cell.className = "kp-day";

      const number = document.createElement("strong");
      number.textContent = day;
      cell.append(number);

      entries
        .filter((entry) => entry.entry_date === date)
        .forEach((entry) => {
          const item = document.createElement("div");
          item.className = `kp-day-entry${entry.special ? " kp-special" : ""}`;
          item.textContent = `${entry.title}${entry.special ? " ★" : ""}`;
          item.title = entry.description || entry.title;
          cell.append(item);
        });

      grid.append(cell);
    }

    renderList();
  }

  function renderList() {
    list.replaceChildren();

    if (!entries.length) {
      list.textContent = "Keine Einträge in diesem Monat.";
      return;
    }

    entries.forEach((entry) => {
      const article = document.createElement("article");
      article.className = "kp-list-item";

      const title = document.createElement("strong");
      title.textContent = `${entry.entry_date} – ${entry.title}`;
      article.append(title);

      if (entry.special) {
        const badge = document.createElement("span");
        badge.className = "kp-special-label";
        badge.textContent = "Besonderes Vorkommnis";
        article.append(badge);
      }

      if (entry.description) {
        const description = document.createElement("p");
        description.textContent = entry.description;
        article.append(description);
      }

      list.append(article);
    });
  }

  root.querySelector("[data-prev]").addEventListener("click", () => {
    current.setMonth(current.getMonth() - 1);
    load();
  });

  root.querySelector("[data-next]").addEventListener("click", () => {
    current.setMonth(current.getMonth() + 1);
    load();
  });

  load();
})();