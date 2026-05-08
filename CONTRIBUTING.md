# Contributing to FerayPro Tracer

Thank you for your interest in contributing to FerayPro Tracer. This is an open-source project under MIT license — every contribution, however small, directly improves the accuracy of environmental and child health impact data for recycling operations across Africa and beyond.

---

## 🌍 Why Your Contribution Matters

Every new keyword you add means one more material type gets a precise CO₂ factor instead of the default 1.0 t/t. Every language you add means collectors in a new country can publish listings that generate verified impact data.

The most valuable contributions right now:

1. **New material keywords** in any language
2. **Arabic, Lingala, Swahili translations** of existing keywords
3. **Emission factor corrections** with source citations
4. **Bug reports** and reproducible issues
5. **New country configurations** (field names, units)

---

## 🚀 Getting Started

### Fork and clone

```bash
git clone https://github.com/feraypro/feraypro-tracer.git
cd feraypro-tracer
```

### Local setup

1. Install WordPress locally (Local by Flywheel, XAMPP, or Docker)
2. Install HivePress + ListingHive
3. Copy the plugin folder to `wp-content/plugins/feraypro-tracer/`
4. Activate in WordPress admin
5. Create a test listing and verify CO₂ calculation

---

## 📝 How to Add New Keywords

The keyword list is in `feraypro-tracer.php` inside the `fpt_co2_factors()` function.

### Format

```php
'keyword_in_lowercase' => FACTOR_VALUE,
```

### Rules

- Keywords must be **lowercase** and **without accents** (accents are stripped automatically)
- Each keyword maps to a **t CO₂ / t recycled** value
- Always include a **comment** with the source for new factors
- Add both French AND English variants when possible
- More specific keywords should come **before** more general ones

### Example — adding "bronze plombé" (leaded bronze)

```php
// Leaded bronze — higher lead content shifts factor toward lead (1.2) vs pure bronze (3.2)
// Source: ADEME Base Carbone, alloys section
'bronze plombe'    => 2.8,
'leaded bronze'    => 2.8,
```

### Step-by-step

1. Open `feraypro-tracer.php`
2. Find the relevant category section (MÉTAUX NON FERREUX, ÉLECTRONIQUE, etc.)
3. Add your keyword(s) with factor and source comment
4. Test with a listing title containing your keyword
5. Verify the CO₂ calculation is correct
6. Submit a pull request

---

## 🌐 Adding a New Language

To add Arabic, Lingala, Swahili, Portuguese, or any other language:

1. Add keywords in the target language to `fpt_co2_factors()`:

```php
// Arabic keywords (Morocco)
'نحاس'     => 3.5,   // copper
'ألومنيوم'  => 9.5,   // aluminum
'حديد'     => 1.8,   // iron/steel
'خردة'     => 1.8,   // scrap

// Lingala keywords (DRC)
'moziki'   => 1.8,   // iron/metal
'cuivri'   => 3.5,   // copper (loanword)
```

2. Update the language selector in the admin settings if adding a new UI language
3. Document the new keywords in `METHODOLOGY.md`
4. Submit a pull request with a description of the language and region

---

## 🔬 Correcting Emission Factors

If you believe an emission factor is incorrect or outdated:

1. Open a GitHub Issue titled `[Factor] <material name> — correction`
2. Include:
   - Current factor in the plugin
   - Your proposed factor
   - Source citation (peer-reviewed paper, official database, government report)
   - Geographic scope of the correction (global / regional)
3. We will review and update within 2 weeks

**Accepted sources:**
- ADEME Base Carbone
- EPA GHG Inventory
- IPCC reports
- National environmental agencies
- Peer-reviewed journals (cite DOI)

**Not accepted:**
- Manufacturer claims without independent verification
- Blog posts or news articles
- Undated or unsourced estimates

---

## 🐛 Reporting Bugs

Open a GitHub Issue with:

1. **Plugin version** (find in FP Tracer → Dashboard)
2. **WordPress version**
3. **HivePress version**
4. **Steps to reproduce**
5. **Expected behavior**
6. **Actual behavior**
7. **Screenshot** if relevant

### Common issues to check first

- Meta key mismatch: verify Field Names in HivePress → Listings → Attributes
- Weight field empty: only listings with a non-zero weight are traced
- Wrong post type: the plugin hooks on `hp_listing` post type

---

## 🌍 Adding a New Country Configuration

If you deploy FerayPro Tracer in a new country, please share your configuration:

1. Open a GitHub Issue titled `[Country] <country name> — configuration`
2. Include:
   - Country name and subdomain
   - Language
   - Weight unit (kg / lb)
   - HivePress field names for: weight, city, phone, price
   - Any locale-specific notes

We will add it to the README country configuration table.

---

## ✅ Pull Request Guidelines

1. **One PR per change** — don't mix keyword additions with bug fixes
2. **Descriptive title** — e.g. `Add Arabic keywords for ferrous metals (Morocco)`
3. **Test your changes** locally before submitting
4. **Reference issues** — link to the Issue your PR resolves
5. **Explain your sources** — especially for new emission factors

### PR checklist

- [ ] Keywords are lowercase and accent-free
- [ ] Source cited in comment for new/modified factors
- [ ] Tested locally with a real listing title
- [ ] README updated if new country added
- [ ] METHODOLOGY.md updated if factors changed

---

## 📜 Code of Conduct

This project is committed to a welcoming and inclusive community. We follow the [Contributor Covenant](https://www.contributor-covenant.org/) Code of Conduct.

In particular:
- Be respectful of different knowledge levels and languages
- Acknowledge that contributors from the field (Morocco, DRC, Senegal...) have expertise that cannot be found in research papers
- Prioritize contributions that improve accuracy for African markets

---

## 📫 Contact

For questions not suitable for GitHub Issues:
- Open a Discussion on GitHub
- contact@feraypro.com

---

*Every keyword is a batch correctly traced. Every correctly traced batch is a child's exposure documented. Thank you for contributing.*
