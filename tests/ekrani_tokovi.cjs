/* Tokovi (koraci) za snimanje ekrana. Svaki tok dobija stranicu i pomoćne funkcije. */
module.exports = {
    async prijava(page, p) {
        await page.goto(p.base + '/login.php');
        await p.slika('01-prijava-radnik');
        await page.locator('label.ime', { hasText: 'Marko' }).click();
        await page.locator('[data-cifra="1"]').click();
        await page.locator('[data-cifra="2"]').click();
        await p.slika('02-prijava-radnik-pin');
        await page.goto(p.base + '/login.php?admin=1');
        await p.slika('03-prijava-admin');
    },
};
