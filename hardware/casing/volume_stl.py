# Volume file STL (mm3). Dipakai buat.sh untuk cek tabrakan.
import re, struct, sys

d = open(sys.argv[1], "rb").read()
if d[:5] == b"solid" and b"facet" in d[:300]:
    v = [tuple(map(float, m)) for m in re.findall(rb"vertex\s+(\S+)\s+(\S+)\s+(\S+)", d)]
    tris = [v[i:i + 3] for i in range(0, len(v), 3)]
else:
    n = struct.unpack("<I", d[80:84])[0]
    tris = []
    for i in range(n):
        f = struct.unpack("<12f", d[84 + i * 50:84 + i * 50 + 48])
        tris.append((f[3:6], f[6:9], f[9:12]))
s = sum(a[0] * (b[1] * c[2] - b[2] * c[1]) - a[1] * (b[0] * c[2] - b[2] * c[0]) + a[2] * (b[0] * c[1] - b[1] * c[0])
        for a, b, c in tris)
print(f"{abs(s) / 6:.2f}")
