<?php
class QemuVm {
	public $monitor;
	private $devices;
	public $status;
	protected $host;
	protected $monitor_port;
	private $ram;
	private $image;
	private $vmID;
	public $vnc_port;
	public function __construct($id){
		$this->monitor = null;
		$this->devices = array();
		$this->host = "localhost";
		$this->vmID = $id;
		$get = mysql_query("SELECT * FROM vm WHERE vmID='".$id."'");
		if(mysql_num_rows($get)){
			$data = mysql_fetch_assoc($get);
			$this->ram = $data['ram'];
			$this->image = Image::getImagePath($data['image']);
			$this->imageType = Image::getImageType($data['image']);
			$this->monitor_port = $GLOBALS['config']['monitorport_min'] + (int)$data['vmID'];
			$this->vnc_port = $GLOBALS['config']['vncport_min'] + (int)$data['vmID'];
			$this->status = $data['status'];
			$this->name = $data['name'];
			$this->password = $data['password'];
		}
		else{
			throw new Exception("Unkown VM ID");
		}
	}

	public function startVM(){
		$cmd = $GLOBALS['config']['qemu_executable'];
		$cmd .=" -L ".$GLOBALS['config']['qemu_bios_folder'];
		$cmd .=" -m ".$this->ram;
		$cmd .=" -".$this->imageType." ".$this->image;
		$cmd .=" -soundhw all";
		$cmd .=" -localtime";
		$cmd .=" -M isapc";
		$cmd .=" -monitor telnet:localhost:".$this->monitor_port.",server,nowait";
		$cmd .=" -vnc :".$this->vmID;
			
		$this->executeStart($cmd);
		$this->setStatus(QemuMonitor::RUNNING);
		mysql_query("UPDATE vm SET lastrun=NOW() WHERE vmID='".$this->vmID."'");
	}

	public function setStatus($status){
		$this->status = $status;
		mysql_query("UPDATE vm SET status='".$status."' WHERE vmID='".$this->vmID."'");
	}
	
	/**
	 * Connect to the Monitor
	 * if needed
	 */
	public function connect(){
		$this->monitor = new QemuMonitor($this->host, $this->monitor_port);
		$this->setStatus(QemuMonitor::RUNNING);
	}
	/**
	 * Get the current status from Qemu
	 * Return the status
	 * @return running, stopped, paused
	 */
	public function getStatus(){
		if($this->monitor){
			$this->monitor->execute("info status");
			$lines = explode("\n",$this->monitor->getResponse());
			foreach($lines as $line){
				if(strstr($line, ":")){
					list(,$status) = explode(": ",$line);
					//$this->status = trim($status);
					$this->setStatus(trim($status));
					break;
				}
			}
			return $this->status;
		}
	}
	/**
	 * Reads all Block Devices (hdd,cd,..) from Qemu
	 */
	public function getBlockDevices(){
		if($this->monitor){
			$this->monitor->execute("info block");
			$lines = explode("\n",$this->monitor->getResponse());
			foreach($lines as $line){
				if(strstr($line, ":")){
					list($dev,$param) = explode(":",$line);
					$this->devices[trim($dev)] = trim($param);
				}
			}
		}
	}
	/**
	 * Pauses the Emulation
	 */
	public function stop(){
		$this->monitor->execute("stop");
		$this->getStatus();
	}
	/**
	 * Resums the Emulation
	 */
	public function resume(){
		$this->monitor->execute("cont");
		$this->getStatus();
	}

	/**
	 * Shuts down the emulation
	 */
	public function shutdown(){
		$this->monitor->execute("system_powerdown");
		usleep(3000);
		$this->monitor->execute("quit");
		$this->setStatus(QemuMonitor::SHUTDOWN);
	}

	/**
	 * Eject a Device from Emulation
	 * @param string $device String from getBlockDevice
	 * @param boolean $force Force to remove the device
	 * @throws Exception
	 */
	public function ejectDevice($device,$force = false){
		if(in_array($device,$this->devices)){
			if($force){
				$this->monitor->execute("eject -f ".$device);
			}
			else{
				$this->monitor->execute("eject ".$device);
			}
		}
		else{
			throw new Exception("Unkown device. Maybe getBlockDevices not called");
		}
	}

	/**
	 * Set a new File for Device
	 * @param string $device
	 * @param string $file Path to iso
	 */
	public function changeDevice($device,$file){
		$this->monitor->execute("change ".$device." ".$file);
	}

	/**
	 * Set the VNC connection password
	 * @param string $password The new password
	 */
	public function setVncPassword($password){
		$this->monitor->execute("set_password vnc ".$password);
	}

	/**
	 * Create a Screenshot from the VM
	 * @param string $filename Path of the PNG to save
	 */
	public function createScreenshot($filename){
		$this->monitor->execute("screendump ".$filename.".ppm");
		$ppm = new PpmImageReader();
		$img = $ppm->read($filename.".ppm");
		if(is_resource($img[1])){
			unlink($filename.".ppm");
			return imagepng($img[1],$filename,4);
		}
		else{
			return false;
		}
	}

	private function executeStart($cmd){
		/**
		 * @Todo logging einbauen
		 * $cmd = $cmd.">".$GLOBALS['config']['log_path']."\\vm_".$this->vmID."_".date("d_m_Y_H_i").".log";
		 */
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$WshShell = new COM("WScript.Shell");
			//$cmd = $cmd.">".$GLOBALS['config']['log_path']."\\vm_".$this->vmID."_".date("d_m_Y_H_i").".log";
			$WshShell->Run($cmd, 0, false);
		}
		else{
			//$cmd = $cmd." > ".$GLOBALS['config']['log_path']."\\vm_".$this->vmID."_".date("d.m.Y H:i").".log &";
			$cmd =  $cmd." > /dev/null &";
			exec($cmd);
		}
	}
}