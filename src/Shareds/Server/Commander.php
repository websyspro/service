<?php

namespace Websyspro\Core\Shareds\Server;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Websyspro\Core\Commons\Collection;
use Websyspro\Core\Interfaces\Server\Watch;

class Commander
{
  public function __construct(
		private Envs $envs
	){
		switch($this->envs->args()->command){
			case "server": $this->server();
				break;
			case "watch": $this->watch();
				break;
		}
	}

	private function server(
	): void {
		$port = $this->envs->args()->flags["port"] 
			?? getenv("PORT");
		
		exec("php -S localhost:{$port}");
	}

	private function watchFiles(
	): Collection {
		$watchs = new Collection();

		$fileFromRII = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator(
				rootDir . "/src"
			)
    );

		foreach ($fileFromRII as $file) {
			if($file->IsDir() === false) {
				$watchs->add(new Watch(
					md5($file->getPathname()),
					$file->getMTime(),
					$file->getPathname()	
				));
			}
		}

		return $watchs;
	}

	public function watchClear(
	): void {
		passthru( "cls" );
		passthru( "clear" );
	}	

	private function watchExec(
	): void {
		passthru( "php index.php");
	}

	private function watch(
	): never {
		$watchOld = $this->watchFiles();

		while(true){
			sleep( 1 );

			$watchNew = $this->watchFiles();
			
			$whtchEditFiles = $watchNew->where(
				fn(Watch $wn) => (
					$watchOld->where(fn(Watch $wp) => (
						$wn->hash === $wp->hash && 
						$wn->timestemp !== $wp->timestemp
					))->exist()
				)
			);

			if($whtchEditFiles->exist()){
				$this->watchClear();
				$this->watchExec();
			} 

			$wathNewFiles = $watchNew->where(
				fn(Watch $wn) => (
					$watchOld->where(fn(Watch $wp) => (
						$wn->hash === $wp->hash
					))->exist() === false
				)
			);

			if($wathNewFiles->exist()) {
				$this->watchClear();
				$this->watchExec();
			}
			
			$wathDropFiles = $watchOld->where(
				fn(Watch $wn) => (
					$watchNew->where(fn(Watch $wp) => (
						$wn->hash === $wp->hash
					))->exist() === false
				)
			);

			if($wathDropFiles->exist()) {
				$this->watchClear();
				$this->watchExec();
			}

			$watchOld = $watchNew;
		}
	}
}