# Third-party notices

pesi is self-contained: it makes no CDN requests and has no Composer
dependencies. One third-party component is **bundled directly inside
`pesi.php`** (CSS + minified JS) and therefore redistributed with this project.
Its license requires the notices below to travel with every copy.

---

## Quill Editor v2.0.3

- Project: https://quilljs.com
- Source: https://github.com/slab/quill
- Upstream license: https://github.com/slab/quill/blob/main/LICENSE
- License: **BSD-3-Clause**
- Bundled in: `pesi.php` — the `.ql-*` CSS block and the minified Quill script,
  both rendered only when a page contains a `richtext` field.

```
Copyright (c) 2017-2024, Slab
Copyright (c) 2014, Jason Chen
Copyright (c) 2013, salesforce.com

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

---

### When updating the bundled Quill

Re-check the copyright years and the license text against the upstream `LICENSE`
file at the version you vendor in, and update this file accordingly. The
copyright header inside the CSS block of `pesi.php` should be kept intact — do
not strip it when minifying.
