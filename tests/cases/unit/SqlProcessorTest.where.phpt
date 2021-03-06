<?php

/** @testCase */

namespace NextrasTests\Dbal;

use DateTime;
use Mockery;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\InvalidArgumentException;
use Nextras\Dbal\SqlProcessor;
use stdClass;
use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';


class SqlProcessorWhereTest extends TestCase
{
	/** @var IDriver|Mockery\MockInterface */
	private $driver;

	/** @var SqlProcessor */
	private $parser;


	protected function setUp()
	{
		parent::setUp();
		$this->driver = Mockery::mock(IDriver::class);
		$this->parser = new SqlProcessor($this->driver);
	}


	/**
	 * @dataProvider provideImplicitTypesData
	 * @dataProvider provideExplicitTypesData
	 */
	public function testImplicitAndExplicitTypes($expected, $operands)
	{
		$this->driver->shouldReceive('convertIdentifierToSql')->with('col')->andReturn('`col`');
		$this->driver->shouldReceive('convertStringToSql')->with('x')->andReturn('"x"');
		$this->driver->shouldReceive('convertDateTimeToSql')->with(Mockery::type('DateTime'))->andReturn('DT');

		Assert::same($expected, $this->parser->processModifier('and', $operands));
	}


	public function provideImplicitTypesData()
	{
		return [
			[
				'`col` = 123',
				['col' => 123]
			],
			[
				'`col` = 123.4',
				['col' => 123.4]
			],
			[
				'`col` = "x"',
				['col' => 'x']
			],
			[
				'`col` = DT',
				['col' => new DateTime('2014-05-01')]
			],
			[
				'`col` IS NULL',
				['col' => NULL]
			],
			[
				'`col` IN (1, 2, 3)',
				['col' => [1, 2, 3]]
			],
			[
				'`col` IN ((1, 2), (3, 4))',
				['col' => [[1, 2], [3, 4]]]
			],
			[
				'`col` IN ("x", (1, ("x", DT), 3))',
				['col' => ['x', [1, ['x', new DateTime('2014-05-01')], 3]]]
			],
		];
	}


	public function provideExplicitTypesData()
	{
		return [
			[
				'`col` = 123',
				['col%i' => 123],
			],
			[
				'`col` = 123.4',
				['col%f' => 123.4],
			],
			[
				'`col` = "x"',
				['col%s' => 'x'],
			],
			[
				'`col` = DT',
				['col%dt' => new DateTime('2014-05-01')],
			],
			[
				'`col` IS NULL',
				['col%?i' => NULL],
			],
			[
				'`col` IN (1, 2, 3)',
				['col%i[]' => [1, 2, 3]],
			],
			[
				'`col` IN ((1, 2), (3, 4))',
				['col%i[][]' => [[1, 2], [3, 4]]],
			],
			[
				'`col` = `col`',
				['col%column' => 'col'],
			],
			[
				'`col` = NOW() + 5',
				['col%ex' => ['NOW() + %i', 5]],
			],
			[
				'`col` = NOW() + %i',
				['col%raw' => 'NOW() + %i'],
			],
		];
	}


	public function testAssoc()
	{
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('a')->andReturn('A');
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('b.c')->andReturn('BC');
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('d')->andReturn('D');
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('e')->andReturn('E');
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('f')->andReturn('F');

		$this->driver->shouldReceive('convertStringToSql')->once()->with(1)->andReturn("'1'");
		$this->driver->shouldReceive('convertStringToSql')->twice()->with('a')->andReturn("'a'");

		Assert::same(
			'A = 1 AND BC = 2 AND D IS NULL AND E IN (\'1\', \'a\') AND F IN (1, \'a\')',
			$this->parser->processModifier('and', [
				'a%i' => '1',
				'b.c' => 2,
				'd%?s' => NULL,
				'e%s[]' => ['1', 'a'],
				'f%any' => [1, 'a'],
			])
		);
	}


	public function testComplex()
	{
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('a')->andReturn('a');
		$this->driver->shouldReceive('convertIdentifierToSql')->once()->with('b')->andReturn('b');

		Assert::same(
			'(a = 1 AND b IS NULL) OR a = 2 OR (a IS NULL AND b = 1) OR b = 3',
			$this->parser->processModifier('or', [
				['%and', ['a%?i' => 1, 'b%?i' => NULL]],
				'a' => 2,
				['%and', ['a%?i' => NULL, 'b%?i' => 1]],
				'b' => 3,
			])
		);
	}


	public function testEmptyConds()
	{
		Assert::same(
			'1=1',
			$this->parser->processModifier('and', [])
		);

		Assert::same(
			'1=1',
			$this->parser->processModifier('or', [])
		);
	}


	/**
	 * @dataProvider provideInvalidData
	 */
	public function testInvalid($type, $value, $message)
	{
		$this->driver->shouldIgnoreMissing();
		Assert::throws(
			function() use ($type, $value) {
				$this->parser->processModifier($type, $value);
			},
			InvalidArgumentException::class, $message
		);
	}


	public function provideInvalidData()
	{
		return [
			['and', 123, 'Modifier %and expects value to be array, integer given.'],
			['and', NULL, 'Modifier %and expects value to be array, NULL given.'],

			['and', ['s'], 'Modifier %and requires items with numeric index to be array, string given.'],
			['and', ['a%i' => 's'], 'Modifier %i expects value to be int, string given.'],
			['and', ['a%i[]' => 123], 'Modifier %i[] expects value to be array, integer given.'],
			['and', ['a' => new stdClass()], 'Modifier %any expects value to be pretty much anything, stdClass given.'],
			['and', ['a%foo' => 's'], 'Unknown modifier %foo.'],

			['?and', [], 'Modifier %and does not have %?and variant.'],
			['and[]', [], 'Modifier %and does not have %and[] variant.'],
			['?and[]', [], 'Modifier %and does not have %?and[] variant.'],

			['or', 123, 'Modifier %or expects value to be array, integer given.'],
			['or', NULL, 'Modifier %or expects value to be array, NULL given.'],

			['or', ['s'], 'Modifier %or requires items with numeric index to be array, string given.'],
			['or', ['a%i' => 's'], 'Modifier %i expects value to be int, string given.'],
			['or', ['a%i[]' => 123], 'Modifier %i[] expects value to be array, integer given.'],
			['or', ['a' => new stdClass()], 'Modifier %any expects value to be pretty much anything, stdClass given.'],
			['or', ['a%foo' => 's'], 'Unknown modifier %foo.'],

			['?or', [], 'Modifier %or does not have %?or variant.'],
			['or[]', [], 'Modifier %or does not have %or[] variant.'],
			['?or[]', [], 'Modifier %or does not have %?or[] variant.'],
		];
	}

}

$test = new SqlProcessorWhereTest();
$test->run();
