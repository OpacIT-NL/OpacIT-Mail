<?php

namespace opacit_mail\Engine\Plugins;

//class PropertyCollection extends \opacit_mail\Mail\Base\Collection
class PropertyCollection extends \ArrayObject implements \JsonSerializable
{
	/**
	 * @var string
	 */
	private $sLabel;

	function __construct(string $sLabel)
	{
		$this->sLabel = $sLabel;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return array(
			'@Object' => 'Object/PluginProperty',
			'type' => \opacit_mail\Engine\Enumerations\PluginPropertyType::GROUP->value,
			'label' => $this->sLabel,
			'config' => $this->getArrayCopy()
/*
			'config' => [
				'@Object' => 'Collection/PropertyCollection',
				'@Collection' => $this->getArrayCopy(),
			]
*/
		);
	}
}
