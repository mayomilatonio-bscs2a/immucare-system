(() => {
    const savedTheme = localStorage.getItem("immucare-theme");
    if (savedTheme === "dark") {
        document.documentElement.dataset.theme = "dark";
    }

    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
            const isDark = document.documentElement.dataset.theme !== "dark";
            document.documentElement.dataset.theme = isDark ? "dark" : "light";
            localStorage.setItem("immucare-theme", isDark ? "dark" : "light");
        });
    });

    document.querySelectorAll("[data-theme-choice]").forEach((button) => {
        button.addEventListener("click", () => {
            const theme = button.dataset.themeChoice;
            if (theme !== "dark" && theme !== "light") {
                return;
            }
            document.documentElement.dataset.theme = theme;
            localStorage.setItem("immucare-theme", theme);
        });
    });
})();
