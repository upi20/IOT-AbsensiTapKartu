# Menarik jalur tembaga otomatis dengan Freerouting, lalu menambah tuangan GND.
# Dipanggil oleh buat.sh dengan Python bawaan KiCad: rute.py <papan.kicad_pcb>
# Kadang ada jalur yang tidak tersambung. Kalau begitu diulang dengan urutan acak (maks. 3 kali);
# kalau tetap gagal, buat.sh membuat ulang papan (urutan komponen di file berubah) lalu mencoba lagi.
import os
import subprocess
import sys
import pcbnew as p

papan = sys.argv[1]
dasar = os.path.splitext(papan)[0]
alat = os.path.join(os.path.dirname(os.path.abspath(__file__)), "alat")
jar = os.path.join(alat, "freerouting-2.4.1.jar")
java = next(os.path.join(alat, d, "Contents/Home/bin/java") for d in os.listdir(alat) if d.startswith("jdk-"))

for percobaan in range(1, 4):
    board = p.LoadBoard(papan)                     # papan tanpa jalur, dari buat_pcb.py
    if not p.ExportSpecctraDSN(board, dasar + ".dsn"):
        raise SystemExit("Gagal membuat file .dsn")
    subprocess.run([java, "-Djava.awt.headless=true", "-jar", jar, "--gui.enabled=false",
                    "--api_server.enabled=false", "--usage_and_diagnostic_data.disable_analytics=true",
                    "-de", dasar + ".dsn", "-do", dasar + ".ses", "-mp", "100"]
                   + ([] if percobaan == 1 else ["-is", "random"]),     # ulangan: urutan acak, hasil berbeda
                   check=True, cwd=alat, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if not p.ImportSpecctraSES(board, dasar + ".ses"):
        raise SystemExit("Gagal membaca hasil Freerouting (.ses)")
    board.BuildConnectivity()
    sisa = board.GetConnectivity().GetUnconnectedCount(True)
    print(f"   Percobaan {percobaan}: {sisa} jalur belum tersambung")
    if sisa == 0:
        break
else:
    raise SystemExit("Freerouting gagal menyambung semua jalur. Ubah posisi komponen di buat_pcb.py.")

# Tuangan GND seluas papan di sisi belakang (area antena dikecualikan oleh rule area).
# Sisi depan tidak dituang: penuh jalur, tuangan di sana hanya jadi pulau-pulau kecil.
kotak = board.GetBoardEdgesBoundingBox()
z = p.ZONE(board)
z.SetLayer(p.B_Cu)
z.SetNet(board.FindNet("GND"))
z.SetLocalClearance(p.FromMM(0.4))
z.SetMinThickness(p.FromMM(0.3))
z.SetPadConnection(p.ZONE_CONNECTION_THERMAL)
z.SetIslandRemovalMode(p.ISLAND_REMOVAL_MODE_ALWAYS)
o = z.Outline()
o.NewOutline()
m = p.FromMM(0.5)
for x, y in ((kotak.GetLeft() + m, kotak.GetTop() + m), (kotak.GetRight() - m, kotak.GetTop() + m),
             (kotak.GetRight() - m, kotak.GetBottom() - m), (kotak.GetLeft() + m, kotak.GetBottom() - m)):
    o.Append(x, y)
board.Add(z)
p.ZONE_FILLER(board).Fill(board.Zones())

p.SaveBoard(papan, board)
os.remove(dasar + ".dsn")
os.remove(dasar + ".ses")
print("   Jalur selesai:", len(list(board.GetTracks())), "segmen/via")
