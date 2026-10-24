# Pick-ImportFolder.ps1 - Edutek importer (patch 4e): the Windows folder window behind the page's "Browse..." button.
# Started by the drive watcher (Watch-ImportDrives.ps1, task "Edutek Import Drive Watcher") when the page writes
# data\folder-pick\request.json. That request holds only a random id and a start folder; nothing in it is ever run.
# Shows the folder window on top of the desktop with keyboard focus (Enter chooses, Esc cancels), then writes
# data\folder-pick\result.json with the chosen folder (or "cancelled") and gives focus back to the browser.
# It never writes to any drive. The importer page checks the chosen folder against the protected-folder rules.
# Test without any window (scratch folder only):
#   .\Pick-ImportFolder.ps1 -Root <scratch root> -DryRun -DryAnswer 'D:\Media'     (or -DryAnswer cancel)
#   .\Pick-ImportFolder.ps1 -CheckOnly        compiles the window code and creates (does not show) the dialog object
param(
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [switch]$DryRun, [string]$DryAnswer = 'cancel', [switch]$CheckOnly, [int]$MaxAgeSeconds = 20
)
$ErrorActionPreference = 'Stop'
$enc = New-Object System.Text.UTF8Encoding($false)
$cs = @'
using System;
using System.Drawing;
using System.Runtime.InteropServices;
using System.Windows.Forms;
namespace EdutekPicker {
  [ComImport, Guid("DC1C5A9C-E88A-4dde-A5A1-60F82A20AEF7")] internal class FileOpenDialogCo { }
  [ComImport, Guid("43826D1E-E718-42EE-BC55-A1E261C37BFE"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
  internal interface IShellItem {
    void BindToHandler(IntPtr pbc, [In] ref Guid bhid, [In] ref Guid riid, out IntPtr ppv);
    void GetParent(out IShellItem ppsi);
    void GetDisplayName(uint sigdnName, [MarshalAs(UnmanagedType.LPWStr)] out string ppszName);
    void GetAttributes(uint sfgaoMask, out uint psfgaoAttribs);
    void Compare(IShellItem psi, uint hint, out int piOrder);
  }
  [ComImport, Guid("42F85136-DB7E-439C-85F1-E4075D135FC8"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
  internal interface IFileDialog {
    [PreserveSig] int Show(IntPtr hwndOwner);
    void SetFileTypes(uint cFileTypes, IntPtr rgFilterSpec);
    void SetFileTypeIndex(uint iFileType);
    void GetFileTypeIndex(out uint piFileType);
    void Advise(IntPtr pfde, out uint pdwCookie);
    void Unadvise(uint dwCookie);
    void SetOptions(uint fos);
    void GetOptions(out uint pfos);
    void SetDefaultFolder(IShellItem psi);
    void SetFolder(IShellItem psi);
    void GetFolder(out IShellItem ppsi);
    void GetCurrentSelection(out IShellItem ppsi);
    void SetFileName([MarshalAs(UnmanagedType.LPWStr)] string pszName);
    void GetFileName([MarshalAs(UnmanagedType.LPWStr)] out string pszName);
    void SetTitle([MarshalAs(UnmanagedType.LPWStr)] string pszTitle);
    void SetOkButtonLabel([MarshalAs(UnmanagedType.LPWStr)] string pszText);
    void SetFileNameLabel([MarshalAs(UnmanagedType.LPWStr)] string pszLabel);
    void GetResult(out IShellItem ppsi);
    void AddPlace(IShellItem psi, int fdap);
    void SetDefaultExtension([MarshalAs(UnmanagedType.LPWStr)] string pszDefaultExtension);
    void Close(int hr);
    void SetClientGuid([In] ref Guid guid);
    void ClearClientData();
    void SetFilter(IntPtr pFilter);
  }
  public static class FolderWindow {
    [DllImport("shell32.dll", CharSet = CharSet.Unicode, PreserveSig = false)]
    private static extern void SHCreateItemFromParsingName(string pszPath, IntPtr pbc, [In] ref Guid riid, out IShellItem ppv);
    [DllImport("user32.dll")] private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] private static extern bool SetForegroundWindow(IntPtr h);
    [DllImport("user32.dll")] private static extern bool BringWindowToTop(IntPtr h);
    [DllImport("user32.dll")] private static extern bool IsWindow(IntPtr h);
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(IntPtr h, IntPtr pid);
    [DllImport("user32.dll")] private static extern bool AttachThreadInput(uint a, uint b, bool attach);
    [DllImport("kernel32.dll")] private static extern uint GetCurrentThreadId();
    private const uint FOS_NOCHANGEDIR = 0x8, FOS_PICKFOLDERS = 0x20, FOS_FORCEFILESYSTEM = 0x40, FOS_PATHMUSTEXIST = 0x800, FOS_DONTADDTORECENT = 0x2000000;
    private const uint SIGDN_FILESYSPATH = 0x80058000;
    private const int HR_CANCELLED = unchecked((int)0x800704C7);
    public static string Kind = "";
    // Windows only lets the foreground window's thread hand over focus, so borrow its input queue for the switch.
    private static void Front(IntPtr target, IntPtr current) {
      uint me = GetCurrentThreadId(); uint other = current == IntPtr.Zero ? 0 : GetWindowThreadProcessId(current, IntPtr.Zero);
      bool attached = other != 0 && other != me && AttachThreadInput(me, other, true);
      try { BringWindowToTop(target); SetForegroundWindow(target); } finally { if (attached) AttachThreadInput(me, other, false); }
    }
    private static IFileDialog Create() { try { return (IFileDialog)new FileOpenDialogCo(); } catch { return null; } }
    // For checks: creates the dialog object without showing anything.
    public static bool CanCreate() { IFileDialog d = Create(); if (d == null) return false; Marshal.ReleaseComObject(d); return true; }
    public static string Pick(string initial, string title, string okLabel) {
      IntPtr previous = GetForegroundWindow();
      using (Form owner = new Form()) {
        owner.Text = title; owner.TopMost = true; owner.ShowInTaskbar = true; owner.FormBorderStyle = FormBorderStyle.None;
        owner.StartPosition = FormStartPosition.CenterScreen; owner.Size = new Size(1, 1); owner.Opacity = 0;
        owner.Show(); Front(owner.Handle, previous); owner.Activate(); Application.DoEvents();
        string result = null;
        try {
          IFileDialog d = Create();
          if (d != null) { Kind = "folder window"; result = Modern(d, owner.Handle, initial, title, okLabel); }
          else { Kind = "classic folder window"; result = Classic(owner, initial, title); }
        } finally {
          if (previous != IntPtr.Zero && IsWindow(previous)) Front(previous, GetForegroundWindow());
          owner.Hide();
        }
        return result;
      }
    }
    private static string Modern(IFileDialog d, IntPtr owner, string initial, string title, string okLabel) {
      try {
        uint o; d.GetOptions(out o);
        d.SetOptions(o | FOS_PICKFOLDERS | FOS_FORCEFILESYSTEM | FOS_PATHMUSTEXIST | FOS_NOCHANGEDIR | FOS_DONTADDTORECENT);
        d.SetTitle(title); d.SetOkButtonLabel(okLabel);
        if (!string.IsNullOrEmpty(initial)) {
          try { Guid g = typeof(IShellItem).GUID; IShellItem f; SHCreateItemFromParsingName(initial, IntPtr.Zero, ref g, out f); d.SetFolder(f); Marshal.ReleaseComObject(f); } catch { }
        }
        int hr = d.Show(owner);
        if (hr == HR_CANCELLED) return null;
        if (hr != 0) Marshal.ThrowExceptionForHR(hr);
        IShellItem item; d.GetResult(out item);
        string path; item.GetDisplayName(SIGDN_FILESYSPATH, out path); Marshal.ReleaseComObject(item);
        return path;
      } finally { Marshal.ReleaseComObject(d); }
    }
    private static string Classic(IWin32Window owner, string initial, string title) {
      using (FolderBrowserDialog f = new FolderBrowserDialog()) {
        f.Description = title; f.ShowNewFolderButton = false; if (!string.IsNullOrEmpty(initial)) f.SelectedPath = initial;
        return f.ShowDialog(owner) == DialogResult.OK ? f.SelectedPath : null;
      }
    }
  }
}
'@
function Load-Window { Add-Type -TypeDefinition $cs -ReferencedAssemblies System.Windows.Forms, System.Drawing; [System.Windows.Forms.Application]::EnableVisualStyles() }
if ($CheckOnly) {
  Load-Window
  if ([Threading.Thread]::CurrentThread.GetApartmentState() -ne 'STA') { 'WARNING: not STA (the watcher starts this script with -STA)' }
  "Folder window code compiled. Dialog object can be created: $([EdutekPicker.FolderWindow]::CanCreate())"; return
}
$pq = Join-Path $Root 'data\folder-pick'; $req = Join-Path $pq 'request.json'; $open = Join-Path $pq 'open.json'; $res = Join-Path $pq 'result.json'
function Save-Json([string]$p, $o) { $t = $p + '.' + [guid]::NewGuid().ToString('N') + '.tmp'; [IO.File]::WriteAllText($t, ($o | ConvertTo-Json -Depth 4), $enc); Move-Item -LiteralPath $t -Destination $p -Force }
function Answer([string]$id, [string]$state, [string]$path, [string]$msg) {
  Save-Json $res ([ordered]@{ id = $id; state = $state; path = $path; message = $msg; at = (Get-Date).ToString('o') })
  Remove-Item -LiteralPath $open -ErrorAction SilentlyContinue; "answered: $state $path"
}
if (-not (Test-Path -LiteralPath $req -PathType Leaf)) { 'no folder request'; exit 0 }
if (Test-Path -LiteralPath $open) {
  if (((Get-Date) - (Get-Item -LiteralPath $open).LastWriteTime).TotalSeconds -lt 330) { 'a folder window is already open'; exit 0 }
  Remove-Item -LiteralPath $open -Force
}
try { Move-Item -LiteralPath $req -Destination $open } catch { 'the request was taken by another folder window'; exit 0 }
$fi = Get-Item -LiteralPath $open; $age = ((Get-Date) - $fi.LastWriteTime).TotalSeconds
if ($fi.Length -gt 4096) { Remove-Item -LiteralPath $open; 'request too large, ignored'; exit 1 }
try { $j = [IO.File]::ReadAllText($open, $enc) | ConvertFrom-Json } catch { Remove-Item -LiteralPath $open; 'unreadable request, ignored'; exit 1 }
$id = [string]$j.id
if ($id -cnotmatch '^[a-f0-9]{32}$') { Remove-Item -LiteralPath $open; 'invalid request id, ignored'; exit 1 }
if ($age -gt $MaxAgeSeconds) { Answer $id 'expired' '' 'The request was too old, so no window was shown.'; exit 0 }
# Start folder: drive letter + path text from the page ("d/Media/Videos"), only used to open the window there.
$start = [string]$j.start; $init = ''
if ($start.Length -le 1024 -and $start -cmatch '^[a-z](/[^/\x00-\x1f\\:*?"<>|]{1,255}){0,40}$' -and -not (@($start -split '/') -contains '..')) {
  $w = $start.Substring(0, 1).ToUpper() + ':\' + ($start.Substring(1).TrimStart('/') -replace '/', '\')
  if (Test-Path -LiteralPath $w -PathType Container) { $init = $w }
}
if (-not $init) { $d = Join-Path $env:USERPROFILE 'Desktop'; if (Test-Path -LiteralPath $d -PathType Container) { $init = $d } }
try {
  if ($DryRun) { "dry run: no window shown (start folder: '$init')"; $picked = $(if ($DryAnswer -eq 'cancel') { $null } else { $DryAnswer }) }
  else { Load-Window; $picked = [EdutekPicker.FolderWindow]::Pick($init, 'Choose a folder to import from (files are only read, never changed)', '&Use this folder') }
} catch { Answer $id 'error' '' ("The folder window could not be opened: " + $_.Exception.Message); exit 1 }
if ([string]::IsNullOrEmpty($picked)) { Answer $id 'cancelled' '' ''; exit 0 }
if ($picked -notmatch '^[A-Za-z]:\\') { Answer $id 'error' $picked "Choose a folder on one of this computer's drives (C:, D: or a USB drive), not a network or library location"; exit 0 }
Answer $id 'picked' $picked ''
